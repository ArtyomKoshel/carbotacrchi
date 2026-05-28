<?php

namespace App\Console\Commands;

use App\Models\CatalogGrade;
use App\Models\CatalogModel;
use App\Models\CatalogModelGeneration;
use App\Models\CatalogSubGrade;
use App\Models\CatalogTokenMap;
use App\Support\Taxonomy\TaxonomyCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CatalogImport extends Command
{
    protected $signature = 'catalog:import
        {--file= : Absolute path to encar_taxonomy_raw.json (default: ../analysis/encar_taxonomy_raw.json)}
        {--fresh : Truncate tables before import}
        {--skip-generations : Skip seeding built-in generation codes}
        {--skip-tokens : Skip seeding built-in token maps}';

    protected $description = 'Import encar_taxonomy_raw.json and seed known generation codes into catalog tables';

    /**
     * Seed data for catalog_token_maps.
     * null mapped_value = exclusion list (token matches the pattern but is NOT that thing).
     * string mapped_value = canonical value (token → value).
     */
    private const TOKEN_MAP_SEED = [
        // ── Fuel type detection ──────────────────────────────────────────
        'fuel' => [
            '디젤' => 'diesel', 'diesel' => 'diesel',
            '가솔린' => 'gasoline', 'gasoline' => 'gasoline', 'petrol' => 'gasoline',
            'lpi' => 'lpg', 'lpe' => 'lpg', 'lpg' => 'lpg', '바이퓨얼' => 'lpg',
            'hev' => 'hybrid', '하이브리드' => 'hybrid',
            'phev' => 'plugin_hybrid', 'plug-in' => 'plugin_hybrid',
            'ev' => 'electric', '전기' => 'electric', 'bev' => 'electric',
            '수소' => 'hydrogen', 'fcev' => 'hydrogen',
            'mhev' => 'mild_hybrid',
        ],
        // ── Drive type detection ─────────────────────────────────────────
        'drive' => [
            '2wd' => 'fwd', 'fwd' => 'fwd', 'ff' => 'fwd',
            '4wd' => 'awd', 'awd' => 'awd', '4x4' => 'awd',
            '콰트로' => 'awd', 'quattro' => 'awd',
            'xdrive' => 'awd',      // BMW
            '4matic' => 'awd',      // Mercedes-Benz
            'e-four' => 'awd',      // Toyota hybrid
            'sdrive' => 'rwd', 'rwd' => 'rwd', 'fr' => 'rwd',
        ],
        // ── Body type detection ──────────────────────────────────────────
        'body' => [
            '쿠페' => 'coupe', 'coupe' => 'coupe',
            '카브리올레' => 'convertible', '컨버터블' => 'convertible',
            'cabriolet' => 'convertible', 'convertible' => 'convertible',
            '로드스터' => 'convertible', 'roadster' => 'convertible',
            '왜건' => 'wagon', 'wagon' => 'wagon', 'estate' => 'wagon', '투어링' => 'wagon',
            '해치백' => 'hatchback', '5도어' => 'hatchback', 'hatchback' => 'hatchback',
            '세단' => 'sedan', 'sedan' => 'sedan', 'saloon' => 'sedan',
            '픽업' => 'pickup', 'pickup' => 'pickup',
            '밴' => 'van', 'van' => 'van',
            '리무진' => 'limousine',
            'suv' => 'suv',
            'crossover' => 'crossover',     // EV crossovers (아이오닉5, EV6, GV60) — AI may return
            'minivan'   => 'minivan',        // 미니밴 (카니발, 스타렉스) — AI may return
        ],
        // ── Tokens that look like generation codes but are NOT ───────────
        // (used to protect isGenerationToken from false positives)
        'gen_non_chassis' => [
            // Powertrain / fuel system
            'ev' => null, 'hev' => null, 'phev' => null, 'bev' => null, 'fhev' => null, 'mhev' => null,
            'gdi' => null, 'tdi' => null, 'tfsi' => null, 'mpi' => null, 'tsi' => null, 'fsi' => null,
            'crdi' => null, 'vgt' => null, 'tci' => null, 'lpi' => null, 'lpg' => null, 'lpe' => null,
            'cng' => null,
            // Drive
            'awd' => null, 'fwd' => null, 'rwd' => null, '4wd' => null, '2wd' => null,
            // Genesis model codes (model names, not chassis)
            'g70' => null, 'g80' => null, 'g90' => null,
            'gv60' => null, 'gv70' => null, 'gv80' => null, 'gv90' => null, 'eq900' => null,
            // Kia EV model codes (model names that look like chassis codes)
            'ev6' => null, 'ev9' => null, 'ev3' => null,
            // Volvo model designations
            'xc40' => null, 'xc60' => null, 'xc90' => null,
            's60' => null, 's90' => null, 'v60' => null, 'v90' => null, 'c40' => null,
            // Volvo engine/powertrain codes
            'd3' => null, 'd4' => null, 'd5' => null,
            't4' => null, 't5' => null, 't6' => null, 't8' => null,
            'b4' => null, 'b5' => null, 'b6' => null,
            // Audi model series
            'q3' => null, 'q5' => null, 'q7' => null, 'q8' => null,
            // BMW i-series / ix
            'ix' => null, 'i3' => null, 'i4' => null, 'i5' => null, 'i7' => null,
        ],
        // ── Cylinder configuration codes — stripped from grade_kr to get trim_kr ─
        // (V6, V8, etc. are engine config, not trim names)
        'cylinder_config' => [
            'v6'  => null, 'v8'  => null, 'v10' => null, 'v12' => null,
            'w12' => null, 'w16' => null,
            'i4'  => null, 'i6'  => null,  // inline-4 / inline-6
            'h4'  => null, 'h6'  => null,  // Subaru horizontally-opposed
        ],
        // ── Engine/powertrain grade codes stripped from grade_kr to get trim_kr ──
        // These codes appear as LEADING tokens in grade strings and are NOT trim names.
        // (e.g. "E200 아방가르드" → strip "e200" → trim "아방가르드")
        'grade_spec_code' => [
            // ── Mercedes-Benz ────────────────────────────────────────────────
            // A-Class
            'a180' => null, 'a200' => null, 'a200d' => null,
            'a220' => null, 'a250' => null, 'a45' => null,
            // B-Class
            'b200' => null, 'b220' => null,
            // C-Class
            'c180' => null, 'c200' => null, 'c200d' => null, 'c200k' => null,
            'c220' => null, 'c220d' => null, 'c230' => null, 'c240' => null,
            'c250' => null, 'c250d' => null,
            'c300' => null, 'c300d' => null, 'c300e' => null, 'c350e' => null,
            'c43' => null, 'c63' => null,
            // CLA
            'cla180' => null, 'cla200' => null, 'cla220' => null, 'cla250' => null,
            'cla35' => null, 'cla45' => null,
            // CLE
            'cle200' => null, 'cle450' => null,
            // CLS
            'cls250' => null, 'cls300d' => null, 'cls350' => null, 'cls350d' => null,
            'cls400' => null, 'cls400d' => null, 'cls450' => null,
            'cls53' => null, 'cls63' => null,
            // E-Class
            'e180' => null, 'e200' => null, 'e200d' => null, 'e200k' => null,
            'e220' => null, 'e220d' => null, 'e240' => null, 'e250' => null, 'e250d' => null,
            'e280' => null, 'e300' => null, 'e300d' => null, 'e300e' => null,
            'e350' => null, 'e350d' => null, 'e400' => null, 'e450' => null,
            'e43' => null, 'e53' => null, 'e63' => null,
            // G-Class
            'g350' => null, 'g400d' => null, 'g450d' => null, 'g500' => null,
            'g550' => null, 'g580' => null, 'g63' => null,
            // GLA / GLB
            'gla200' => null, 'gla220' => null, 'gla250' => null,
            'gla35' => null, 'gla45' => null,
            'glb200' => null, 'glb200d' => null, 'glb220' => null, 'glb220d' => null,
            'glb250' => null, 'glb35' => null,
            // GLC
            'glc200' => null, 'glc220' => null, 'glc220d' => null, 'glc250' => null,
            'glc300' => null, 'glc300e' => null, 'glc350e' => null,
            'glc43' => null, 'glc63' => null,
            // GLE
            'gle250' => null, 'gle300d' => null, 'gle350' => null, 'gle350d' => null,
            'gle350e' => null, 'gle400d' => null, 'gle400e' => null,
            'gle450' => null, 'gle450d' => null, 'gle53' => null, 'gle63' => null,
            // GLS / GL
            'gls350' => null, 'gls350d' => null, 'gls400d' => null,
            'gls450' => null, 'gls450d' => null,
            'gls500' => null, 'gls580' => null, 'gls63' => null,
            // GLK / ML (older)
            'glk220' => null, 'glk250' => null, 'glk300' => null, 'glk350' => null,
            'ml280' => null, 'ml300' => null, 'ml350' => null, 'ml63' => null,
            // R-Class
            'r350' => null,
            // S-Class
            's350' => null, 's350d' => null, 's350l' => null,
            's400' => null, 's400d' => null, 's400l' => null,
            's450' => null, 's450l' => null,
            's500' => null, 's500l' => null,
            's550' => null, 's550l' => null, 's550v' => null,
            's560' => null, 's560el' => null, 's560l' => null,
            's580e' => null, 's580l' => null,
            's600' => null,
            's63' => null, 's63l' => null, 's65' => null,
            // SL / SLC
            'sl400' => null, 'sl550' => null, 'sl63' => null, 'sl65' => null,
            'slc200' => null,
            // V-Class
            'v300' => null,
            // CL (older coupe)
            'cl500' => null, 'cl600' => null, 'cl63' => null, 'cl65' => null,
            // EQ electric series
            'eqa250' => null, 'eqb300' => null, 'eqc400' => null,
            'eqe350' => null, 'eqe500' => null,
            'eqs350' => null, 'eqs450' => null, 'eqs580' => null,
            // Older codes
            '230k' => null,
            // ── BMW ──────────────────────────────────────────────────────────
            // 1-Series
            '116d' => null, '116i' => null, '118d' => null, '118i' => null,
            '120d' => null, '120i' => null, '125d' => null, '125i' => null,
            '130i' => null, 'm135' => null, 'm135i' => null, 'm140i' => null,
            // 2-Series
            '216d' => null, '218d' => null, '218i' => null,
            '220d' => null, '220i' => null, '225d' => null,
            '228i' => null, '230d' => null, '230e' => null, '230i' => null,
            'm235i' => null, 'm240i' => null,
            // 3-Series
            '316d' => null, '316i' => null, '318ci' => null,
            '318d' => null, '318i' => null,
            '320d' => null, '320e' => null, '320i' => null, '323i' => null,
            '325d' => null, '325i' => null, '328d' => null, '328i' => null, '328xi' => null,
            '330d' => null, '330e' => null, '330i' => null,
            '335d' => null, '335i' => null, 'm340d' => null, 'm340i' => null,
            // 4-Series
            '418d' => null, '418i' => null, '420d' => null, '420i' => null,
            '425d' => null, '428d' => null, '428i' => null,
            '430d' => null, '430i' => null, '435d' => null, '435i' => null,
            '440i' => null, 'm440d' => null, 'm440i' => null,
            // 5-Series
            '518d' => null, '520d' => null, '520i' => null,
            '523d' => null, '525d' => null, '525i' => null,
            '528d' => null, '528i' => null, '530d' => null, '530e' => null, '530i' => null,
            '535d' => null, '535i' => null, '540d' => null, '540i' => null,
            '545e' => null, '550i' => null, 'm550d' => null, 'm550i' => null,
            // 6-Series
            '620d' => null, '625d' => null, '628i' => null,
            '630d' => null, '630i' => null, '635d' => null,
            '640d' => null, '640i' => null, '650i' => null,
            // 7-Series
            '728i' => null, '730d' => null, '730i' => null, '730ld' => null,
            '735i' => null, '740d' => null, '740e' => null,
            '740i' => null, '740ld' => null, '740li' => null,
            '745e' => null, '745le' => null, '745li' => null,
            '750e' => null, '750i' => null, '750li' => null,
            '760i' => null, '760li' => null, 'm760i' => null, 'm760li' => null,
            // 8-Series
            '840d' => null, '840i' => null, '850i' => null, 'm850i' => null,
            // M standalone
            'm2' => null, 'm3' => null, 'm4' => null, 'm5' => null,
            'm6' => null, 'm8' => null,
            // M Performance (X-series)
            'm35i' => null, 'm40i' => null,
            'm50' => null, 'm50d' => null, 'm50i' => null,
            'm60' => null, 'm60i' => null,
            // X-series short tokens (appear without xDrive prefix in grade_kr)
            '30d' => null, '40d' => null, '50i' => null,
            // X-series combined tokens (xDriveXXi/d appear as single token in grade_kr)
            'xdrive18d' => null, 'xdrive18i' => null,
            'xdrive20d' => null, 'xdrive20i' => null,
            'xdrive25d' => null, 'xdrive25i' => null,
            'xdrive28i' => null, 'xdrive30d' => null, 'xdrive30e' => null, 'xdrive30i' => null,
            'xdrive35d' => null, 'xdrive35i' => null,
            'xdrive40d' => null, 'xdrive40e' => null, 'xdrive40i' => null,
            'xdrive45e' => null, 'xdrive50' => null, 'xdrive50i' => null,
            'sdrive18d' => null, 'sdrive18i' => null,
            'sdrive20d' => null, 'sdrive20i' => null, 'sdrive28i' => null, 'sdrive30i' => null,
            // iX / i-series electric
            'edrive40' => null,
            // ── Audi ─────────────────────────────────────────────────────────
            '35tfsi' => null, '40tfsi' => null, '45tfsi' => null,
            '50tfsi' => null, '55tfsi' => null, '60tfsi' => null,
            '30tdi' => null, '35tdi' => null, '40tdi' => null, '45tdi' => null,
            '50tdi' => null, '55tdi' => null,
            // ── Hyundai Genesis (BH platform, 1st gen) ───────────────────────
            'bh330' => null, 'bh380' => null,
            // ── Hyundai Equus (JS / VI platform) ─────────────────────────────
            'js380' => null, 'js500' => null,
            'vl380' => null, 'vl500' => null,  // Equus limousine
            // ── Hyundai Grandeur composite gen+engine tokens ─────────────────
            'hg220' => null, 'hg240' => null, 'hg260' => null,
            // hg300/hg330 already in gen_exclude; repeat here for grade stripping
            'hg300' => null, 'hg330' => null,
            // ── Hyundai / Kia misc engine-grade codes ─────────────────────────
            'm16' => null,  // 1.6L engine badge (Avante/Sonata)
            'y20' => null,  // Grand Starex 20-seater code
            'q240' => null, 'q270' => null,  // Renault Samsung QM5/QM6 grades
            // ── Genesis power-output codes ────────────────────────────────────
            // g300/g350/g400 already in gen_exclude; repeat for grade stripping
            'g300' => null, 'g330' => null, 'g350' => null, 'g380' => null,
            'g400' => null, 'g400d' => null, 'g450' => null,
            // ── Kia K9 (Quoris) engine codes ─────────────────────────────────
            'gh270' => null,                   // 1st gen K9 (GH platform) 2.7L
            '500h' => null, '700h' => null,    // K9 hybrid variants (Kia/Lexus)
            // ── SsangYong / KG Mobility engine codes ─────────────────────────
            'vs380' => null, 'vs500' => null,
            'cw600' => null, 'cw700' => null, 'el300' => null,
            // ── Land Rover ────────────────────────────────────────────────────
            'sdv6' => null, 'sdv8' => null,
            'td4' => null, 'td6' => null, 'td8' => null,
            'si4' => null, 'si6' => null,
            'd180' => null, 'd200' => null, 'd250' => null, 'd300' => null, 'd350' => null,
            'p250' => null, 'p300' => null, 'p360' => null, 'p400' => null,
            'p530' => null, 'p525' => null, 'p550e' => null, 'p615' => null,
            // ── Jaguar ────────────────────────────────────────────────────────
            '20d' => null, '25t' => null, '30d' => null,  // older Ingenium codes (F-Pace, XE, XF)
            // ── Lexus ─────────────────────────────────────────────────────────
            '300h' => null, '450h' => null, '500h' => null,
            // 700h shared with Kia above
            // ── Tesla ─────────────────────────────────────────────────────────
            '90d' => null, '100d' => null,  // Model S/X (older Long Range designators)
            // ── Chevrolet ─────────────────────────────────────────────────────
            'cl240' => null, 'el240' => null,  // Captiva/Lacetti engine codes
            // ── Volvo powertrain badges ───────────────────────────────────────
            // D = diesel, T = petrol/PHEV, B = mild-hybrid
            // All appear as LEADING tokens in grade_kr (e.g. "B5 인스크립션" → trim "인스크립션")
            'd2' => null, 'd3' => null, 'd4' => null, 'd5' => null,
            't2' => null, 't3' => null, 't4' => null, 't5' => null, 't6' => null, 't8' => null,
            'b3' => null, 'b4' => null, 'b5' => null, 'b6' => null, 'b8' => null,
            // ── Lexus model-grade codes ───────────────────────────────────────
            // Appear as leading token in grade_kr (e.g. "IS250 F 스포츠" → trim "F 스포츠")
            // CT
            'ct200h' => null,
            // ES
            'es250' => null, 'es300' => null, 'es300h' => null, 'es330' => null, 'es350' => null,
            // GS
            'gs200t' => null, 'gs250' => null, 'gs300' => null, 'gs300h' => null,
            'gs350' => null, 'gs400' => null, 'gs430' => null, 'gs450h' => null, 'gs460' => null,
            // GX
            'gx460' => null, 'gx470' => null, 'gx550' => null,
            // HS
            'hs250h' => null,
            // IS
            'is200t' => null, 'is250' => null, 'is300' => null, 'is300h' => null,
            'is350' => null, 'is500' => null,
            // LC
            'lc500' => null, 'lc500h' => null,
            // LS
            'ls400' => null, 'ls430' => null, 'ls460' => null,
            'ls500' => null, 'ls500h' => null, 'ls600h' => null, 'ls600hl' => null,
            // LX
            'lx450' => null, 'lx470' => null, 'lx570' => null, 'lx600' => null,
            // NX
            'nx200t' => null, 'nx250' => null, 'nx300' => null, 'nx300h' => null,
            'nx350' => null, 'nx350h' => null, 'nx450h' => null,
            // RC
            'rc200t' => null, 'rc300' => null, 'rc300h' => null, 'rc350' => null,
            // RX
            'rx200t' => null, 'rx270' => null, 'rx300' => null, 'rx330' => null,
            'rx350' => null, 'rx350h' => null, 'rx450h' => null, 'rx500h' => null,
            // UX
            'ux200' => null, 'ux250h' => null,
            // ── Audi S/RS performance designations ────────────────────────────
            // Appear as leading token when S/RS is in the grade_kr (e.g. "RS6 아반트")
            'rs3' => null, 'rs4' => null, 'rs5' => null, 'rs6' => null, 'rs7' => null,
            's3' => null, 's4' => null, 's5' => null, 's6' => null, 's7' => null, 's8' => null,
            // ── Lamborghini LP engine codes ────────────────────────────────────
            'lp550' => null, 'lp550-2' => null,
            'lp560' => null, 'lp560-4' => null,
            'lp570' => null, 'lp570-4' => null,
            'lp580' => null, 'lp580-2' => null,
            'lp610' => null, 'lp610-4' => null,
            'lp620' => null, 'lp620-4' => null,
            'lp640' => null, 'lp640-4' => null,
            'lp700' => null, 'lp700-4' => null,
            'lp720' => null, 'lp720-4' => null,
            'lp740' => null, 'lp740-4' => null,
            'lp750' => null, 'lp750-4' => null,
            'lp770' => null, 'lp770-4' => null,
            // ── Maserati AWD designation ──────────────────────────────────────────
            'q4' => null,  // Maserati Q4 AWD system ("Q4 그란루쏘" → trim "그란루쏘")
            // ── Cadillac engine/variant codes ─────────────────────────────────────
            'ct4' => null, 'ct5' => null, 'ct6' => null,  // model codes in grade_kr
            'xt4' => null, 'xt5' => null, 'xt6' => null,
            // ── Infiniti old-generation model codes ──────────────────────────────
            // Pre-Q/QX rename era; appear as leading token in grade_kr
            'fx35' => null, 'fx37' => null, 'fx45' => null, 'fx50' => null,
            'g25' => null, 'g35' => null, 'g37' => null,
            'm35' => null, 'm35h' => null, 'm37' => null, 'm45' => null, 'm56' => null,
            'ex35' => null, 'ex37' => null,
            'jx35' => null, 'qx56' => null,
            // ── Cylinder-config tokens as leading grade prefix ────────────────────
            // e.g. Bentley "W12 스탠다드" → strip → "스탠다드"; Dodge "V8 SRT" → "SRT"
            'v6' => null, 'v8' => null, 'v10' => null, 'v12' => null,
            'w8' => null, 'w12' => null, 'w16' => null,
            // ── Renault Korea engine codes ─────────────────────────────────────────
            're16' => null, 're19' => null, 're20' => null,  // Samsung SM/QM engine codes
            // ── MINI drive system (appears as token in grade_kr) ──────────────
            // ALL4 is MINI's AWD system — strip so "ALL4 JCW" → "JCW"
            'all4' => null,
        ],
        // ── Engine displacement tokens stripped from grade_kr ─────────────
        'grade_engine_vol' => [
            // Plain decimal volumes common in Korean market
            '1.0' => null, '1.2' => null, '1.4' => null, '1.5' => null,
            '1.6' => null, '1.8' => null, '2.0' => null, '2.2' => null,
            '2.4' => null, '2.5' => null, '2.7' => null, '3.0' => null,
            '3.3' => null, '3.5' => null, '3.8' => null, '4.0' => null,
            '4.4' => null, '4.7' => null, '5.0' => null, '5.5' => null,
            '6.0' => null, '6.3' => null, '6.5' => null,
            // With T/D/L suffix (common in Genesis/Hyundai/Kia grades: "2.5T 스포츠")
            '1.0t' => null, '1.2t' => null, '1.4t' => null, '1.5t' => null,
            '1.6t' => null, '1.8t' => null,
            '2.0t' => null, '2.2t' => null, '2.4t' => null, '2.5t' => null,
            '3.0t' => null, '3.3t' => null, '3.5t' => null, '4.0t' => null, '4.4t' => null,
            '1.5d' => null, '1.6d' => null, '2.0d' => null, '2.2d' => null,
            '2.4d' => null, '3.0d' => null, '3.5d' => null,
            '1.3' => null, '1.3t' => null,
            '2.3' => null, '2.3t' => null,
            '3.9' => null, '3.9t' => null,
            '4.6' => null, '4.6t' => null,
            '5.6' => null, '5.6t' => null,
            '6.2' => null, '6.4' => null, '6.6' => null, '6.75' => null,
            '5.0l' => null, '6.6l' => null, '6.75l' => null,
        ],
        // ── Korean generation label tokens: 1세대, 2세대 … stripped from grade_kr ─
        'grade_gen_label' => [
            '1세대' => null, '2세대' => null, '3세대' => null, '4세대' => null,
            '5세대' => null, '6세대' => null, '7세대' => null, '8세대' => null,
        ],
        // ── Korean seat-count tokens stripped from grade_kr ───────────────
        'grade_seat' => [
            '5인승' => null, '6인승' => null, '7인승' => null, '8인승' => null,
            '9인승' => null, '10인승' => null, '11인승' => null, '12인승' => null,
            '13인승' => null, '14인승' => null, '15인승' => null,
        ],
        // ── Known trim names — positive lookup for trim validation / Layer-2 extraction ─
        // Tokens in this map are KEPT as trim (not stripped).
        // Used to confirm the stripped remainder is a real trim, not noise.
        'grade_trim_name' => [
            // ── Hyundai (현대) ─────────────────────────────────────────────────
            // Full hierarchy: 스마트 → 스타일 → 모던 → 프리미엄 → 르블랑 → 익스클루시브 → 아너스 → 캘리그래피
            '스마트' => null, '스타일' => null, '모던' => null,
            '프리미엄' => null, '르블랑' => null,
            '익스클루시브' => null, '아너스' => null,
            '캘리그래피' => null,
            // older Hyundai trims
            '디럭스' => null, '럭셔리' => null, '스탠다드' => null,
            '트렌디' => null, '캐쥬얼' => null, '레포츠' => null,
            '베이직' => null, '엔트리' => null,
            // ── Kia (기아) ─────────────────────────────────────────────────────
            // hierarchy: 트렌디 → 프레스티지 → 노블레스 → 시그니처 → 마스터피스
            '트렌디' => null, '프레스티지' => null,
            '노블레스' => null, '마스터피스' => null, '마스터즈' => null,
            '시그니처' => null, '그래비티' => null,
            // English trim codes shared across Kia/Renault/SsangYong
            'se' => null, 'le' => null, 're' => null, 'pe' => null,
            'ex' => null, 'lx' => null, 'rx' => null, 'kx' => null, 'tx' => null,
            // ── Genesis (제네시스) ─────────────────────────────────────────────
            // hierarchy: 프레스티지 → 스포츠 → 스포츠 플러스 → 어드밴스드 → 시그니처 → 캘리그래피
            '어드밴스드' => null, '스포츠' => null,
            // ── SsangYong / KG Mobility ───────────────────────────────────────
            '헤리티지' => null, '인스파이어' => null,
            // old Korando/Rexton grade codes
            'vx' => null, 'dx' => null,
            // ── Chevrolet (쉐보레) Korea ──────────────────────────────────────
            'ls' => null, 'lt' => null, 'ltz' => null,
            '프리미어' => null,   // Premier Korean spelling
            '액티브' => null,     // Activ/Active
            // ── BMW Korea ─────────────────────────────────────────────────────
            // Sedan/Coupe: Pure / Sport / M Sport / Luxury / Executive / Urban / Comfort
            '퓨어' => null, '어반' => null, '이그제큐티브' => null, '컴포트' => null,
            // SUV add-on: xLine
            // (M Sport, Luxury, Sport are multi-word or overlap with other brands — kept by default)
            // ── Mercedes-Benz Korea ───────────────────────────────────────────
            // Avantgarde / Exclusive / AMG Line / Elegance / Night / Premium
            '아방가르드' => null, '익스클루시브' => null, '엘레강스' => null,
            '내추럴' => null,  // older MB E-Class trim
            // ── Audi Korea ────────────────────────────────────────────────────
            // Premium / Premium Plus / Prestige / Comfort / Dynamic / Advanced
            '어드밴스' => null, '다이나믹' => null,
            // ── Volvo Korea ───────────────────────────────────────────────────
            // Old: Momentum / Inscription / R-Design    New: Core / Plus / Ultimate
            '모멘텀' => null, '인스크립션' => null,
            '코어' => null, '얼티밋' => null,
            // ── Volkswagen Korea ──────────────────────────────────────────────
            '컴포트라인' => null, '트렌드라인' => null, '하이라인' => null,
            // ── Land Rover / Range Rover Korea ───────────────────────────────
            // SE / HSE / Autobiography / SVR / Dynamic SE / Dynamic HSE / First Edition
            '오토바이오그래피' => null, '퍼스트에디션' => null,
            // ── Renault Korea ─────────────────────────────────────────────────
            '인텐스' => null, '라이프' => null, '리미티드' => null,
            // ── Lexus Korea ───────────────────────────────────────────────────
            // F Sport / Premium / Luxury / Executive / Version L
            '버사체' => null,
            // ── Toyota Korea ──────────────────────────────────────────────────
            // LE / SE / XLE / XSE / GR Sport / Limited
            '그랜드투어링' => null,
            // ── General cross-brand trim words ───────────────────────────────
            '플러스' => null,   // "스포츠 플러스", "인스크립션 플러스"
            '스페셜' => null,   // "모던 스페셜"
            '에디션' => null,   // "퍼스트 에디션", "30주년 에디션"
            '리미티드' => null, // "Limited" used across many brands
        ],
        // ── Korean model name marketing prefixes — stripped to get canonical name ─
        // Sorted by specificity: multi-word before single-word (handled at runtime)
        'model_prefix' => [
            '더 넥스트' => null,  // "The Next" (Chevrolet 더 넥스트 스파크/말리부/크루즈)
            '더 뉴'     => null,  // "The New" facelift
            '올 뉴'     => null,  // "All New" full redesign
            '베리 뉴'   => null,  // "Very New" (Renault Samsung SM3/QM3 older marketing)
            '올뉴'      => null,  // "All New" (no-space variant)
            '더'        => null,  // standalone "더" (older Hyundai/Kia)
            '뉴'        => null,  // "New" (older models)
            '신형'      => null,  // "New model" / revised
        ],
        // ── Hard-exclude specific tokens from generation detection ────────
        'gen_exclude' => [
            'v6' => null, 'v8' => null, 'v10' => null, 'v12' => null,
            'w12' => null, 'w16' => null, 'q4' => null,
            // Genesis engine grade codes (power outputs, not chassis)
            'g300' => null, 'g350' => null, 'g380' => null, 'g400' => null, 'g450' => null,
            // Hyundai Grandeur composite tokens (gen+engine)
            'hg300' => null, 'hg330' => null,
            // SsangYong engine codes
            'vs380' => null, 'cw700' => null, 'el300' => null, 'g330' => null,
            // Lamborghini LP codes (false-positive generation detection)
            'lp550' => null, 'lp560' => null, 'lp570' => null, 'lp580' => null,
            'lp610' => null, 'lp620' => null, 'lp640' => null, 'lp700' => null,
            'lp720' => null, 'lp740' => null, 'lp750' => null, 'lp770' => null,
            // Maserati Q4 AWD
            'q4' => null,
        ],
        // ── Engine family tokens — strip from model name string ───────────
        'engine_family' => [
            'gdi' => null, 't-gdi' => null, 'tgdi' => null, 'gde' => null,
            'mpi' => null, 'mpfi' => null,
            'tfsi' => null, 'tsi' => null, 'fsi' => null,
            'tdi' => null, 'crdi' => null, 'vgt' => null, 'tci' => null, 'e-vgt' => null,
            'fhev' => null, 'hev' => null, 'lpi' => null, 'lpe' => null,
            'ev' => null, 'bev' => null,
            '4matic' => null, '블루텍' => null, 'gte' => null, 'lpli' => null, '4tronic' => null,
            'hemi' => null,  // Chrysler/Dodge Hemispherical engine branding
            'skyactiv' => null, 'vtec' => null, 'cvvt' => null, 'dohc' => null, 'sohc' => null,
        ],
        // ── Tokens that match variant pattern but are NOT variants ────────
        'variant_exclude' => [
            'ev' => null, 'bev' => null, 'hev' => null, 'fhev' => null, 'mhev' => null,
            'gdi' => null, 'tdi' => null, 'mpi' => null, 'crdi' => null,
            'vgt' => null, 'tci' => null, 'tfsi' => null, 'tsi' => null, 'fsi' => null,
            'gde' => null, 'gte' => null, 'lpli' => null, 'lpe' => null, 'lpi' => null,
            '4wd' => null, '2wd' => null, 'awd' => null, 'fwd' => null, 'rwd' => null,
            'v6' => null, 'v8' => null, 'v10' => null, 'v12' => null, 'lpg' => null,
            'g70' => null, 'g80' => null, 'g90' => null,
            'gv70' => null, 'gv80' => null, 'gv90' => null, 'eq900' => null,
            'xc40' => null, 'xc60' => null, 'xc90' => null,
            's60' => null, 's90' => null, 'v60' => null, 'v90' => null, 'c40' => null,
        ],

        // ── Performance variant whitelist (AI post-validator) ─────────────
        // mapped_value = canonical display casing returned to the caller
        'variant_whitelist' => [
            // Mercedes-AMG
            'amg'          => 'AMG',
            'black series' => 'Black Series',
            // Audi RS
            'rs'  => 'RS',
            'rs3' => 'RS3', 'rs4' => 'RS4', 'rs5' => 'RS5',
            'rs6' => 'RS6', 'rs7' => 'RS7', 'rs q3' => 'RS Q3', 'rs q5' => 'RS Q5',
            // BMW M
            'm'           => 'M',
            'competition'  => 'Competition',
            'cs'          => 'CS',
            'm performance' => 'M Performance',
            // Hyundai/Genesis N
            'n' => 'N',
            'n performance' => 'N Performance',
            // MINI JCW
            'jcw' => 'JCW',
            // Subaru STI / WRX
            'sti' => 'STI',
            'wrx' => 'WRX',
            // Honda Type R / Si
            'type r' => 'Type R',
            'type s' => 'Type S',
            // Toyota GR / TRD
            'gr'  => 'GR',
            'trd' => 'TRD',
            // VW GTI / R
            'gti' => 'GTI',
            'r'   => 'R',
            // Porsche
            '4s'      => '4S',
            'gts'     => 'GTS',
            'turbo'   => 'Turbo',
            'turbo s' => 'Turbo S',
            'gt3'     => 'GT3',
            'gt3 rs'  => 'GT3 RS',
            'gt4'     => 'GT4',
            // Nissan
            'nismo' => 'Nismo',
            // Chevrolet SS
            'ss' => 'SS',
            // Kia / Hyundai GT (K5 GT, Stinger GT)
            'gt' => 'GT',
        ],

        // ── Trim level whitelist (AI post-validator) ──────────────────────
        // mapped_value = canonical display form; null = token only used as membership check
        'trim_whitelist' => [
            // ── Hyundai grade names ────────────────────────────────────────
            '스마트'       => '스마트',        // Smart (base)
            '모던'         => '모던',          // Modern
            '프리미엄'     => '프리미엄',      // Premium
            '익스클루시브' => '익스클루시브',  // Exclusive
            '노블레스'     => '노블레스',      // Noblesse
            '캘리그래피'   => '캘리그래피',   // Calligraphy (Sonata/Grandeur top)
            '인스퍼레이션' => '인스퍼레이션', // Inspiration (Tucson/Santa Fe)
            '아너스'       => '아너스',        // Honors (Grandeur GN7 — confirmed danawa)
            '하이클래스'   => '하이클래스',   // Hi-Class (older Grandeur/Sonata)
            '하이테크'     => '하이테크',      // Hi-Tech
            '트렌드'       => '트렌드',        // Trend
            '글로시'       => '글로시',        // Glossy (Sonata special)
            '파퓰러'       => '파퓰러',        // Popular (Avante etc.)
            '밸류'         => '밸류',          // Value
            '어드벤처'     => '어드벤처',      // Adventure (Tucson NX4 — confirmed danawa)
            '블랙잉크'     => '블랙잉크',      // Black Ink appearance pkg (Grandeur/Santa Fe)
            '블랙익스테리어' => '블랙익스테리어', // Black Exterior pkg (Santa Fe MX5)
            // ── Kia grade names ────────────────────────────────────────────
            '트렌디'       => '트렌디',        // Trendy (K5 base — confirmed danawa)
            '프레스티지'   => '프레스티지',    // Prestige
            '시그니처'     => '시그니처',      // Signature (top)
            '마스터피스'   => '마스터피스',    // Masterpiece (K9 top)
            '그래비티'     => '그래비티',      // Gravity (Sorento MQ4 off-road — confirmed danawa)
            '아웃도어'     => '아웃도어',      // Outdoor (Carnival KA4 — confirmed danawa)
            // ── Kia appearance packages (trim, NOT variant) ────────────────
            'x-line'       => 'X-Line',        // Kia X-Line off-road appearance
            'x 라인'       => 'X-Line',
            '엑스라인'     => 'X-Line',
            'gt-line'      => 'GT-Line',        // Kia GT-Line sporty appearance
            'gt 라인'      => 'GT-Line',
            'gt라인'       => 'GT-Line',
            // ── Hyundai appearance packages (trim, NOT variant) ────────────
            'n line'       => 'N Line',         // Hyundai N Line appearance (≠ N variant!)
            'n 라인'       => 'N Line',
            'n라인'        => 'N Line',
            // ── Genesis grade names ────────────────────────────────────────
            '럭셔리'       => '럭셔리',        // Luxury
            '디스팅션'     => '디스팅션',      // Distinction (top)
            '르블랑'       => '르블랑',        // Le Blanc (G80/G90)
            '임프레션'     => '임프레션',      // Impression
            '퍼스트'       => '퍼스트',        // First Edition
            // ── Palisade-specific ──────────────────────────────────────────
            'vip'          => 'VIP',            // Palisade VIP (top, confirmed danawa)
            'h-pick'       => 'H-Pick',         // Santa Fe H-Pick (hybrid special)
            // ── Shared Korean trims ────────────────────────────────────────
            '디럭스'       => '디럭스',        // Deluxe (older models)
            '스탠다드'     => '스탠다드',      // Standard
            '스페셜'       => '스페셜',        // Special edition
            '스포츠'       => '스포츠',        // Sport (appearance)
            '액티브'       => '액티브',        // Active (Chevrolet/Renault)
            '레드라인'     => '레드라인',      // RedLine (Chevrolet)
            '리미티드'     => '리미티드',      // Limited
            '어반'         => '어반',          // Urban
            // ── Chevrolet Korea ────────────────────────────────────────────
            'ls'     => 'LS',
            'lt'     => 'LT',
            'ltz'    => 'LTZ',
            'activ'  => 'ACTIV',
            'redline' => 'RedLine',
            'premier' => 'Premier',
            // ── BMW trims ──────────────────────────────────────────────────
            'm sport'   => 'M Sport',
            'm 스포츠'  => 'M 스포츠',
            'sport line' => 'Sport Line',
            'luxury line' => 'Luxury Line',
            'xline'     => 'xLine',
            'x라인'     => 'xLine',
            'advantage' => 'Advantage',
            'm sport pack'    => 'M Sport Pack',      // BMW F30 Korea (older 3-Series)
            'm 스포츠 팩'     => 'M Sport Pack',
            'm sport package' => 'M Sport Package',   // BMW G20 Korea (current 3-Series)
            'm 스포츠 패키지' => 'M Sport Package',
            'm sport pro'     => 'M Sport Pro',       // BMW M Sport Pro (enhanced visual)
            'm 스포츠 프로'   => 'M Sport Pro',
            '럭셔리 라인'     => 'Luxury Line',       // BMW Luxury Line (Korean label for Luxury Line)
            // ── Mercedes trims ─────────────────────────────────────────────
            'amg line'   => 'AMG Line',
            'amg 라인'   => 'AMG Line',
            'avantgarde' => 'Avantgarde',
            '아방가르드' => '아방가르드',
            'exclusive'  => 'Exclusive',
            'style'      => 'Style',
            // ── Audi trims ─────────────────────────────────────────────────
            's-line'  => 'S-Line',
            's 라인'  => 'S-Line',
            'dynamic'     => 'Dynamic',
            'technik'     => 'Technik',
            'advanced'    => 'Advanced',       // Audi Advanced (Q5 2025 — confirmed danawa)
            // ── Volkswagen trims ───────────────────────────────────────────
            'progressive' => 'Progressive',   // VW Golf 8 / Tiguan Korea trim
            'elegance'    => 'Elegance',       // VW Arteon Korea trim
            // ── Volvo trims ────────────────────────────────────────────────
            'momentum'    => 'Momentum',
            'inscription' => 'Inscription',
            'r-design'    => 'R-Design',
            'cross country' => 'Cross Country',
            'polestar'    => 'Polestar',
            'core'        => 'Core',           // Volvo Core base trim (2022+ XC60/XC90)
            'plus'        => 'Plus',           // Volvo Plus mid trim (2022+ XC60/XC90)
            'ultra'       => 'Ultra',          // Volvo Ultra top trim (2022+ XC60/XC90)
            // ── Lexus trims ────────────────────────────────────────────────
            'f sport'   => 'F Sport',
            'f 스포츠'  => 'F Sport',
            'luxury'    => 'Luxury',
            'prestige'  => 'Prestige',
            'executive' => 'Executive',
            // ── Toyota/Honda/Nissan ────────────────────────────────────────
            'le'      => 'LE',
            'se'      => 'SE',
            'xle'     => 'XLE',
            'limited' => 'Limited',
            'touring' => 'Touring',
            'sport'   => 'Sport',
            // ── Generic English trims used across brands ───────────────────
            'premium'   => 'Premium',
            'signature' => 'Signature',
            'modern'    => 'Modern',
            'lx'        => 'LX',
            'ex'        => 'EX',
            're'        => 'RE',
            'pe'        => 'PE',
            'mx'        => 'MX',
        ],

        // ── Model name → body type (AI post-validator fallback) ───────────
        // token = lowercase Korean/English model name, mapped_value = body type
        'model_body_hint' => [
            // ── Hyundai 현대 ───────────────────────────────────────────────
            '아반떼'          => 'sedan',
            '쏘나타'          => 'sedan',
            '그랜저'          => 'sedan',
            '쏘나타 n 라인'   => 'sedan',
            '아슬란'          => 'sedan',
            '아이오닉6'       => 'sedan',      // fastback sedan shape
            '제네시스 쿠페'   => 'coupe',
            '벨로스터'        => 'coupe',
            'i10'             => 'hatchback',
            'i20'             => 'hatchback',
            'i30'             => 'hatchback',
            '아이오닉'        => 'hatchback',  // 1세대 Ioniq hybrid/EV
            '캐스퍼'          => 'hatchback',
            '모닝'            => 'hatchback',
            '투싼'            => 'suv',
            '싼타페'          => 'suv',
            '팰리세이드'      => 'suv',
            '코나'            => 'suv',
            '베뉴'            => 'suv',
            '넥쏘'            => 'suv',        // hydrogen SUV
            '아이오닉5'       => 'crossover',  // CUV / crossover
            '아이오닉7'       => 'suv',
            '카니발'          => 'minivan',
            '스타리아'        => 'van',
            '그랜드 스타렉스' => 'minivan',
            '스타렉스'        => 'van',
            '쏠라티'          => 'van',
            '포터'            => 'pickup',
            // ── Kia 기아 ──────────────────────────────────────────────────
            'k3'             => 'sedan',
            'k5'             => 'sedan',
            'k7'             => 'sedan',
            'k8'             => 'sedan',
            'k9'             => 'sedan',
            '스팅어'         => 'sedan',       // gran turismo fastback
            '스포티지'        => 'suv',
            '쏘렌토'          => 'suv',
            '셀토스'          => 'suv',
            '니로'            => 'crossover',
            'ev6'             => 'crossover',
            'ev9'             => 'suv',
            '봉고'            => 'pickup',
            '레이'            => 'van',
            '카렌스'          => 'minivan',
            '쏘울'            => 'hatchback',
            // ── Genesis 제네시스 ───────────────────────────────────────────
            'g70'   => 'sedan',
            'g80'   => 'sedan',
            'g90'   => 'sedan',
            'gv60'  => 'crossover',
            'gv70'  => 'suv',
            'gv80'  => 'suv',
            'gv90'  => 'suv',
            'eq900' => 'sedan',              // Genesis EQ900 = G90 1st gen
            // ── SsangYong/KGM 쌍용 ────────────────────────────────────────
            '티볼리'   => 'suv',
            '렉스턴'   => 'suv',
            '코란도'   => 'suv',
            '토레스'   => 'suv',
            '무쏘'     => 'suv',
            '이스타나' => 'van',
            // ── Renault Samsung 르노삼성 ────────────────────────────────────
            'sm3'  => 'sedan',
            'sm5'  => 'sedan',
            'sm6'  => 'sedan',
            'sm7'  => 'sedan',
            'qm3'  => 'suv',
            'qm5'  => 'suv',
            'qm6'  => 'suv',
            'xm3'  => 'suv',
            // ── GM Korea / Chevrolet 쉐보레 ────────────────────────────────
            '말리부'        => 'sedan',
            '임팔라'        => 'sedan',
            '크루즈'        => 'sedan',
            '스파크'        => 'hatchback',
            '아베오'        => 'sedan',
            '볼트'          => 'hatchback',
            '이쿼녹스'      => 'suv',
            '트래버스'      => 'suv',
            '트랙스'        => 'suv',
            '트레일블레이저' => 'suv',
            '타호'          => 'suv',
            '레조'          => 'minivan',
            // ── BMW ───────────────────────────────────────────────────────
            '1시리즈' => 'hatchback',
            '2시리즈' => 'coupe',        // F22/G42 coupe; F45 MPV less common
            '3시리즈' => 'sedan',
            '4시리즈' => 'coupe',
            '5시리즈' => 'sedan',
            '6시리즈' => 'coupe',
            '7시리즈' => 'sedan',
            '8시리즈' => 'coupe',
            'm2'      => 'coupe',
            'm3'      => 'sedan',
            'm4'      => 'coupe',
            'm5'      => 'sedan',
            'm6'      => 'coupe',
            'm8'      => 'coupe',
            'z3'      => 'convertible',
            'z4'      => 'convertible',
            'x1'      => 'suv',
            'x2'      => 'suv',
            'x3'      => 'suv',
            'x3 m'    => 'suv',
            'x4'      => 'suv',
            'x5'      => 'suv',
            'x5 m'    => 'suv',
            'x6'      => 'suv',
            'x6 m'    => 'suv',
            'x7'      => 'suv',
            'ix'      => 'suv',
            'ix3'     => 'suv',
            'i3'      => 'hatchback',
            'i4'      => 'sedan',
            'i7'      => 'sedan',
            // ── Mercedes-Benz 벤츠 ────────────────────────────────────────
            'a클래스'  => 'hatchback',
            'b클래스'  => 'hatchback',
            'c클래스'  => 'sedan',
            'e클래스'  => 'sedan',
            's클래스'  => 'sedan',
            'cla'      => 'sedan',       // 4-door coupe
            'cls'      => 'sedan',
            'sl'       => 'convertible',
            'slc'      => 'convertible',
            'slk'      => 'convertible',
            'gla'      => 'suv',
            'glb'      => 'suv',
            'glc'      => 'suv',
            'gle'      => 'suv',
            'gls'      => 'suv',
            'g클래스'  => 'suv',
            'g바겐'    => 'suv',
            'eqa'      => 'suv',
            'eqb'      => 'suv',
            'eqc'      => 'suv',
            'eqe'      => 'sedan',
            'eqs'      => 'sedan',
            'eqs suv'  => 'suv',
            'amg gt'   => 'coupe',
            // ── Audi 아우디 ───────────────────────────────────────────────
            'a1'  => 'hatchback',
            'a3'  => 'hatchback',
            'a4'  => 'sedan',
            'a5'  => 'coupe',
            'a6'  => 'sedan',
            'a7'  => 'sedan',            // sportback fastback
            'a8'  => 'sedan',
            'q2'  => 'suv',
            'q3'  => 'suv',
            'q4'  => 'suv',
            'q5'  => 'suv',
            'q7'  => 'suv',
            'q8'  => 'suv',
            'e-tron' => 'suv',
            'e-tron gt' => 'sedan',
            'r8'  => 'coupe',
            'tt'  => 'coupe',
            's3'  => 'hatchback',
            's4'  => 'sedan',
            's5'  => 'coupe',
            's6'  => 'sedan',
            's7'  => 'sedan',
            's8'  => 'sedan',
            'rs3' => 'hatchback',
            'rs4' => 'wagon',
            'rs5' => 'coupe',
            'rs6' => 'wagon',
            'rs7' => 'sedan',
            'sq5'   => 'suv',                // Audi SQ5 — performance SUV
            'sq7'   => 'suv',                // Audi SQ7 — performance SUV
            'sq8'   => 'suv',                // Audi SQ8 — performance SUV
            'rs q3' => 'suv',               // Audi RS Q3 — performance compact SUV
            'rs q5' => 'suv',               // Audi RS Q5 — performance mid SUV
            // ── Volkswagen 폭스바겐 ───────────────────────────────────────
            '폴로'   => 'hatchback',
            '골프'   => 'hatchback',
            '아테온' => 'sedan',
            '파사트' => 'sedan',
            '아르테온' => 'sedan',
            '제타'   => 'sedan',
            '비틀'   => 'hatchback',
            '투아렉' => 'suv',
            '티구안' => 'suv',
            'id.3'   => 'hatchback',
            'id.4'   => 'suv',
            'id.6'   => 'suv',
            't-roc'  => 'suv',
            't-cross' => 'suv',
            // ── Volvo 볼보 ────────────────────────────────────────────────
            's40'  => 'sedan',
            's60'  => 'sedan',
            's80'  => 'sedan',
            's90'  => 'sedan',
            'v40'  => 'hatchback',
            'v50'  => 'wagon',
            'v60'  => 'wagon',
            'v90'  => 'wagon',
            'xc40' => 'suv',
            'xc60' => 'suv',
            'xc70' => 'suv',
            'xc90' => 'suv',
            'c30'  => 'hatchback',
            'c40'  => 'suv',            // C40 Recharge is a coupe-SUV
            // ── Lexus 렉서스 ──────────────────────────────────────────────
            'ct'  => 'hatchback',
            'is'  => 'sedan',
            'es'  => 'sedan',
            'gs'  => 'sedan',
            'ls'  => 'sedan',
            'rc'  => 'coupe',
            'lc'  => 'coupe',
            'nx'  => 'suv',
            'rx'  => 'suv',
            'ux'  => 'suv',
            'gx'  => 'suv',
            'lx'  => 'suv',
            'rz'  => 'suv',
            // ── Toyota 도요타 ─────────────────────────────────────────────
            '코롤라'   => 'sedan',
            '캠리'     => 'sedan',
            '아발론'   => 'sedan',
            '프리우스' => 'hatchback',
            '야리스'   => 'hatchback',
            'rav4'     => 'suv',
            '하이랜더' => 'suv',
            '포춘어'   => 'suv',
            '랜드크루저' => 'suv',
            '하이에이스' => 'van',
            'gr86'     => 'coupe',
            'gr 수프라' => 'coupe',
            '수프라'   => 'coupe',
            'c-hr'     => 'suv',
            // ── Honda 혼다 ────────────────────────────────────────────────
            '시빅'   => 'sedan',
            '어코드' => 'sedan',
            '레전드' => 'sedan',
            '인사이트' => 'sedan',
            'cr-v'   => 'suv',
            'hr-v'   => 'suv',
            'pilot'  => 'suv',
            'odyssey' => 'minivan',
            // ── Porsche 포르쉐 ────────────────────────────────────────────
            '911'      => 'coupe',
            '718'          => 'coupe',      // 718 Cayman default
            '718 케이맨'   => 'coupe',      // Porsche 718 Cayman (explicit)
            '718 박스터'   => 'convertible', // Porsche 718 Boxster (explicit)
            '박스터'       => 'convertible',
            '케이맨'       => 'coupe',
            '마칸'     => 'suv',
            '카이엔'   => 'suv',
            '파나메라' => 'sedan',
            '타이칸'   => 'sedan',
            'taycan cross turismo' => 'wagon',
            // ── Land Rover 랜드로버 ───────────────────────────────────────
            '레인지로버'         => 'suv',
            '레인지로버 스포츠'  => 'suv',
            '레인지로버 벨라'    => 'suv',
            '레인지로버 이보크'  => 'suv',
            '디스커버리'         => 'suv',
            '디스커버리 스포츠'  => 'suv',
            '디펜더'             => 'suv',
            '프리랜더'           => 'suv',
            // ── Jaguar 재규어 ─────────────────────────────────────────────
            'xj'      => 'sedan',
            'xf'      => 'sedan',
            'xe'      => 'sedan',
            'xk'      => 'coupe',
            'f-type'  => 'coupe',
            'f-pace'  => 'suv',
            'e-pace'  => 'suv',
            'i-pace'  => 'suv',
            // ── MINI 미니 ─────────────────────────────────────────────────
            '미니'           => 'hatchback',
            'mini'           => 'hatchback',
            'countryman'     => 'suv',
            'clubman'        => 'wagon',
            'paceman'        => 'suv',
            'cabrio'         => 'convertible',
            // ── Nissan / Infiniti ─────────────────────────────────────────
            '알티마'   => 'sedan',
            '맥시마'   => 'sedan',
            '큐브'     => 'hatchback',
            '티아나'   => 'sedan',
            'qx50'     => 'suv',
            'qx60'     => 'suv',
            'qx70'     => 'suv',
            'qx80'     => 'suv',
            'q50'      => 'sedan',
            'q70'      => 'sedan',
            'fx'       => 'suv',
            // ── Lincoln / Cadillac / American ─────────────────────────────
            '링컨 컨티넨탈' => 'sedan',
            '링컨 타운카'   => 'sedan',
            '링컨 mks'      => 'sedan',
            'escalade'      => 'suv',
            'navigator'     => 'suv',
        ],
    ];

    /**
     * Known chassis/generation codes per model.
     * Keyed by [make_kr, model_kr] → list of codes.
     * Source: manufacturer documentation + public chassis-code references.
     *
     * @var array<string, list<string>>
     */
    private const GENERATION_SEED = [
        // ── 현대 (Hyundai) ───────────────────────────────────────────────
        '현대|그랜저'   => ['TG', 'HG', 'IG', 'GN7'],         // 4/5/6/7세대
        '현대|소나타'   => ['EF', 'NF', 'YF', 'LF', 'DN8'], // 4–8세대
        '현대|아반떼'   => ['XD', 'HD', 'MD', 'AD', 'CN7'], // 3–7세대
        '현대|투싼'     => ['JM', 'LM', 'TL', 'NX4'],       // 1–4세대
        '현대|싼타페'   => ['SM', 'CM', 'DM', 'TM', 'MX5'],    // 1–5세대
        '현대|i30'      => ['FD', 'GD', 'PD'],               // 1–3세대
        '현대|벨로스터' => ['FS'],
        '현대|아이오닉' => ['AE'],                            // Ioniq 1세대 (hybrid/PHEV/EV)
        '현대|아이오닉 5' => ['NE'],                          // Ioniq 5 (2021+, E-GMP)
        '현대|아이오닉 6' => ['CE'],                          // Ioniq 6 (2022+, E-GMP)
        '현대|코나'     => ['OS', 'SX2'],                     // 1/2세대
        '현대|팰리세이드' => ['LX2'],
        '현대|스타리아' => ['US4'],
        '현대|베뉴'     => ['QX'],
        '현대|아슬란'   => ['AG'],
        '현대|넥쏘'     => ['FE'],                            // Nexo hydrogen SUV
        '현대|제네시스 쿠페' => ['BK', 'BH3'],

        // ── 기아 (Kia) ───────────────────────────────────────────────────
        '기아|K5'       => ['MG', 'TF', 'JF', 'DL3'],       // 1–3세대
        '기아|K7'       => ['VG', 'YG'],                     // 1–2세대
        '기아|K8'       => ['GL3'],
        '기아|K3'       => ['LD', 'TD', 'BD'],               // 1–3세대
        '기아|K9'       => ['RI', 'RJ'],
        '기아|스포티지' => ['SL', 'QL', 'NQ5'],              // 3–5세대
        '기아|쏘렌토'   => ['BL', 'XM', 'UM', 'MQ4'],       // 1–4세대
        '기아|모닝'     => ['SA', 'TA', 'JA'],               // 1–3세대
        '기아|쏘울'     => ['AM', 'PS', 'SK3'],              // 1–3세대
        '기아|니로'     => ['DE', 'SG2'],                    // 1–2세대
        '기아|스팅어'   => ['CK'],
        '기아|카니발'   => ['VQ', 'YP', 'KA4'],             // 2–4세대
        '기아|레이'     => ['TAM'],
        '기아|셀토스'   => ['SP2'],
        '기아|봉고'     => ['PU'],
        '기아|EV6'      => ['CV'],                           // EV6 (2021+, E-GMP)
        '기아|EV9'      => ['MV'],                           // EV9 (2023+)
        '기아|EV3'      => ['OV'],                           // EV3 (2024+)

        // ── 제네시스 (Genesis) ───────────────────────────────────────────
        '제네시스|G70'  => ['IK'],
        '제네시스|G80'  => ['RG3', 'RG'],
        '제네시스|G90'  => ['HI', 'RS4'],
        '제네시스|GV70' => ['JK1'],
        '제네시스|GV80' => ['JX1'],
        '제네시스|GV60' => ['JW'],                           // GV60 EV (2021+)
        '제네시스|GV90' => ['RS5'],                          // GV90 (2024+)

        // ── BMW ──────────────────────────────────────────────────────────
        'BMW|1시리즈'   => ['E87', 'F20', 'F40'],
        'BMW|2시리즈'   => ['F22', 'F45', 'G42'],
        'BMW|3시리즈'   => ['E30', 'E36', 'E46', 'E90', 'F30', 'G20'],
        'BMW|4시리즈'   => ['F32', 'F36', 'G22'],
        'BMW|5시리즈'   => ['E34', 'E39', 'E60', 'F10', 'G30'],
        'BMW|6시리즈'   => ['E63', 'F12', 'G32'],
        'BMW|7시리즈'   => ['E38', 'E65', 'F01', 'G11'],
        'BMW|8시리즈'   => ['G14', 'G15'],
        'BMW|X1'        => ['E84', 'F48', 'U11'],
        'BMW|X2'        => ['F39', 'U10'],
        'BMW|X3'        => ['E83', 'F25', 'G01', 'G45'],   // add G45 (4세대, 2024+)
        'BMW|X4'        => ['F26', 'G02'],
        'BMW|X5'        => ['E53', 'E70', 'F15', 'G05'],
        'BMW|X6'        => ['E71', 'F16', 'G06'],
        'BMW|X7'        => ['G07'],
        'BMW|Z4'        => ['E85', 'E89', 'G29'],
        'BMW|M3'        => ['E36', 'E46', 'E90', 'F80', 'G80'],
        'BMW|M5'        => ['E34', 'E39', 'E60', 'F10', 'F90'],

        // ── 벤츠 (Mercedes-Benz) ─────────────────────────────────────────
        '벤츠|A클래스'  => ['W168', 'W169', 'W176', 'W177'],
        '벤츠|B클래스'  => ['W245', 'W246', 'W247'],
        '벤츠|C클래스'  => ['W202', 'W203', 'W204', 'W205', 'W206'],
        '벤츠|E클래스'  => ['W210', 'W211', 'W212', 'W213', 'W214'],
        '벤츠|S클래스'  => ['W140', 'W220', 'W221', 'W222', 'W223'],
        '벤츠|GLC'      => ['X253', 'X254'],
        '벤츠|GLE'      => ['W166', 'V167'],
        '벤츠|GLS'      => ['X166', 'X167'],
        '벤츠|CLA'      => ['C117', 'C118'],
        '벤츠|CLS'      => ['C219', 'C218', 'C257'],
        '벤츠|SL'       => ['R230', 'R231', 'R232'],
        '벤츠|GLB'      => ['X247'],
        '벤츠|EQC'      => ['N293'],
        '벤츠|EQE'      => ['V295'],
        '벤츠|EQS'      => ['V297'],

        // ── 아우디 (Audi) ────────────────────────────────────────────────
        '아우디|A3'     => ['8L', '8P', '8V', '8Y'],
        '아우디|A4'     => ['B5', 'B6', 'B7', 'B8', 'B9'],
        '아우디|A5'     => ['8T', 'F5'],
        '아우디|A6'     => ['C5', 'C6', 'C7', 'C8'],
        '아우디|A7'     => ['4G', 'C8'],
        '아우디|A8'     => ['D2', 'D3', 'D4', 'D5'],
        '아우디|Q3'     => ['8U', 'F3'],
        '아우디|Q5'     => ['8R', 'FY'],
        '아우디|Q7'     => ['4L', '4M'],
        '아우디|Q8'     => ['4M'],
        '아우디|TT'     => ['8N', '8J', 'FV'],

        // ── 폭스바겐 (Volkswagen) ────────────────────────────────────────
        '폭스바겐|골프'  => ['MK4', 'MK5', 'MK6', 'MK7', 'MK8'],
        '폭스바겐|파사트' => ['B5', 'B6', 'B7', 'B8'],
        '폭스바겐|티구안' => ['5N', 'AD1'],

        // ── 볼보 (Volvo) ─────────────────────────────────────────────────
        '볼보|S60'      => ['P24', 'Y20'],
        '볼보|S90'      => ['P238'],
        '볼보|V60'      => ['P24', 'Y20'],
        '볼보|XC60'     => ['DZ', 'U'],
        '볼보|XC90'     => ['C', 'LA'],

        // ── 렉서스 (Lexus) ───────────────────────────────────────────────
        '렉서스|ES'     => ['XV10', 'XV20', 'XV30', 'XV40', 'XV50', 'XV60', 'XV70'],
        '렉서스|IS'     => ['XE10', 'XE20', 'XE30'],
        '렉서스|RX'     => ['XU10', 'XU30', 'AL10', 'AL20'],
        '렉서스|GS'     => ['S140', 'S160', 'S190'],
        '렉서스|LS'     => ['F10', 'F20', 'F30', 'F40', 'F50'],
        '렉서스|NX'     => ['AZ10', 'AZ20'],
        '렉서스|UX'     => ['ZA10'],
        '렉서스|LC'     => ['Z100'],

        // ── 도요타 (Toyota) ──────────────────────────────────────────────
        '도요타|캠리'   => ['XV30', 'XV40', 'XV50', 'XV70'],
        '도요타|RAV4'   => ['XA10', 'XA20', 'XA30', 'XA40', 'XA50'],
        '도요타|프리우스' => ['NHW10', 'NHW20', 'ZVW30', 'ZVW50', 'AXWH50'],

        // ── 혼다 (Honda) ─────────────────────────────────────────────────
        '혼다|어코드'   => ['CG', 'CM', 'CL', 'CP', 'CR', 'CV'],
        '혼다|CR-V'     => ['RD', 'RE', 'RM', 'RW'],
        '혼다|시빅'     => ['EK', 'EP', 'FD', 'FB', 'FC', 'FK'],

        // ── 포르쉐 (Porsche) ─────────────────────────────────────────────
        '포르쉐|카이엔' => ['9PA', '92A', '9YA'],
        '포르쉐|파나메라' => ['970', '971'],
        '포르쉐|911'    => ['993', '996', '997', '991', '992'],
        '포르쉐|마칸'   => ['95B', 'J1'],
        '포르쉐|타이칸' => ['J1'],

        // ── 랜드로버 (Land Rover) ────────────────────────────────────────
        '랜드로버|레인지로버' => ['L322', 'L405', 'L460'],
        '랜드로버|레인지로버 스포츠' => ['L320', 'L494', 'L461'],
        '랜드로버|디스커버리' => ['L319', 'L462'],
        '랜드로버|디펜더' => ['L316', 'L663'],

        // ── 재규어 (Jaguar) ──────────────────────────────────────────────
        '재규어|XF'     => ['X250', 'X260'],
        '재규어|XE'     => ['X760'],
        '재규어|F-Pace' => ['X761'],
        '재규어|E-Pace' => ['X540'],
    ];

    public function handle(): int
    {
        $file = $this->option('file') ?? base_path('../analysis/encar_taxonomy_raw.json');
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $json = file_get_contents($file);
        $raw  = $json ? json_decode($json, true) : null;
        if (!is_array($raw)) {
            $this->error('Failed to decode JSON');
            return self::FAILURE;
        }

        $this->line('Rows in JSON: ' . count($raw));

        if ($this->option('fresh')) {
            $this->line('Truncating catalog tables...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            CatalogTokenMap::query()->truncate();
            CatalogModelGeneration::query()->truncate();
            CatalogSubGrade::query()->truncate();
            CatalogGrade::query()->truncate();
            CatalogModel::query()->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $makeMap    = TaxonomyCatalog::normalizationMap('make');
        $modelCache = [];
        $gradeCache = [];
        $newModels  = 0;
        $newGrades  = 0;
        $newSubGrades = 0;
        $skipped    = 0;

        foreach ($raw as $row) {
            [$makeKr, $modelKr, $gradeKr, $subGrade] = $row;

            if (empty($makeKr) || empty($modelKr) || empty($gradeKr)) {
                $skipped++;
                continue;
            }

            $gradeKr = $this->stripPlaceholder($gradeKr);
            if ($gradeKr === '') {
                $skipped++;
                continue;
            }

            $makeEn   = $makeMap[$makeKr] ?? $makeKr;
            $modelKey = "{$makeKr}|{$modelKr}";

            if (!isset($modelCache[$modelKey])) {
                $model = CatalogModel::firstOrCreate(
                    ['make_kr' => $makeKr, 'model_kr' => $modelKr],
                    ['make_en' => $makeEn]
                );
                $modelCache[$modelKey] = $model->id;
                if ($model->wasRecentlyCreated) {
                    $newModels++;
                }
            }
            $modelId  = $modelCache[$modelKey];
            $gradeKey = "{$modelId}|{$gradeKr}";

            if (!isset($gradeCache[$gradeKey])) {
                $attrs = $this->parseGradeAttrs($gradeKr);
                $grade = CatalogGrade::firstOrCreate(
                    ['model_id' => $modelId, 'grade_kr' => $gradeKr],
                    $attrs
                );
                $gradeCache[$gradeKey] = $grade->id;
                if ($grade->wasRecentlyCreated) {
                    $newGrades++;
                }
            }
            $gradeId = $gradeCache[$gradeKey];

            if ($subGrade !== null) {
                $subGrade = $this->stripPlaceholder((string) $subGrade);
                if ($subGrade !== '') {
                    $sub = CatalogSubGrade::firstOrCreate(
                        ['grade_id' => $gradeId, 'sub_grade_kr' => $subGrade],
                        ['type' => $this->classifySubGrade($subGrade)]
                    );
                    if ($sub->wasRecentlyCreated) {
                        $newSubGrades++;
                    }
                }
            }
        }

        $this->info("New models:     {$newModels}");
        $this->info("New grades:     {$newGrades}");
        $this->info("New sub-grades: {$newSubGrades}");
        $this->info("Skipped rows:   {$skipped}");

        if (!$this->option('skip-generations')) {
            $newGen = $this->seedGenerationCodes($modelCache, $makeMap);
            $this->info("New generation codes: {$newGen}");
        }

        if (!$this->option('skip-tokens')) {
            $newTok = $this->seedTokenMaps();
            $this->info("New token maps:       {$newTok}");
        }

        return self::SUCCESS;
    }

    private function seedTokenMaps(): int
    {
        $new = 0;
        foreach (self::TOKEN_MAP_SEED as $tokenType => $entries) {
            foreach ($entries as $token => $mappedValue) {
                $row = CatalogTokenMap::firstOrCreate(
                    ['token' => mb_strtolower((string) $token), 'token_type' => $tokenType],
                    ['mapped_value' => $mappedValue]
                );
                if ($row->wasRecentlyCreated) {
                    $new++;
                }
            }
        }
        return $new;
    }

    private function seedGenerationCodes(array $modelCache, array $makeMap): int
    {
        $new = 0;

        foreach (self::GENERATION_SEED as $key => $codes) {
            [$makeKr, $modelKr] = explode('|', $key, 2);

            // Look up model_id — may already be in cache or need a DB query
            $cacheKey = "{$makeKr}|{$modelKr}";
            if (isset($modelCache[$cacheKey])) {
                $modelId = $modelCache[$cacheKey];
            } else {
                $model = CatalogModel::where('make_kr', $makeKr)
                    ->where('model_kr', $modelKr)
                    ->first();
                if ($model === null) {
                    // Model not in DB (not in JSON either) — create a stub entry
                    $makeEn  = $makeMap[$makeKr] ?? $makeKr;
                    $model   = CatalogModel::firstOrCreate(
                        ['make_kr' => $makeKr, 'model_kr' => $modelKr],
                        ['make_en' => $makeEn]
                    );
                }
                $modelId = $model->id;
            }

            foreach ($codes as $code) {
                $gen = CatalogModelGeneration::firstOrCreate(
                    ['model_id' => $modelId, 'code' => strtoupper($code)]
                );
                if ($gen->wasRecentlyCreated) {
                    $new++;
                }
            }
        }

        return $new;
    }

    private function stripPlaceholder(string $v): string
    {
        $v = trim($v);
        return (str_starts_with($v, '(') && str_ends_with($v, ')')) ? '' : $v;
    }

    private function classifySubGrade(string $value): string
    {
        if (preg_match('/^[A-Z]{1,4}\d{1,3}[A-Z]?$/', $value)) {
            return 'generation';
        }
        if (preg_match('/^\d+세대$/u', $value)) {
            return 'generation';
        }
        if (preg_match('/[가-힣]/u', $value)) {
            return 'trim';
        }
        return 'unknown';
    }

    private function parseGradeAttrs(string $gradeKr): array
    {
        static $fuelMap  = null;
        static $driveMap = null;
        static $bodyMap  = null;

        if ($fuelMap === null) {
            $fuelMap  = self::TOKEN_MAP_SEED['fuel'];
            $driveMap = self::TOKEN_MAP_SEED['drive'];
            $bodyMap  = self::TOKEN_MAP_SEED['body'];
        }

        $fuel      = null;
        $drive     = null;
        $engineVol = null;
        $seatCount = null;
        $cylinders = null;
        $bodyHint  = null;

        $cylinderMap = ['V6' => 6, 'V8' => 8, 'V10' => 10, 'V12' => 12, 'W12' => 12, 'W16' => 16, 'I4' => 4, 'I6' => 6];

        $tokens = preg_split('/\s+/u', $gradeKr) ?: [];
        foreach ($tokens as $tok) {
            $lower = mb_strtolower($tok);
            $upper = strtoupper($tok);

            if ($fuel === null && isset($fuelMap[$lower])) {
                $fuel = $fuelMap[$lower];
            }
            if ($drive === null && isset($driveMap[$lower])) {
                $drive = $driveMap[$lower];
            }
            if ($bodyHint === null && isset($bodyMap[$lower])) {
                $bodyHint = $bodyMap[$lower];
            }
            if ($cylinders === null && isset($cylinderMap[$upper])) {
                $cylinders = $cylinderMap[$upper];
            }
            if ($engineVol === null && preg_match('/^(\d+\.\d+)$/u', $tok, $m)) {
                $v = (float) $m[1];
                if ($v >= 0.5 && $v <= 10.0) {
                    $engineVol = $v;
                }
            }
            if ($seatCount === null && preg_match('/^(\d{1,2})인승$/u', $tok, $m)) {
                $seatCount = (int) $m[1];
            }
        }

        return [
            'fuel_type'     => $fuel,
            'drive_type'    => $drive,
            'engine_volume' => $engineVol,
            'seat_count'    => $seatCount,
            'cylinders'     => $cylinders,
            'body_hint'     => $bodyHint,
        ];
    }
}
