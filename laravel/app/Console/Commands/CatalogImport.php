<?php

namespace App\Console\Commands;

use App\Models\CatalogGrade;
use App\Models\CatalogModel;
use App\Models\CatalogModelGeneration;
use App\Models\CatalogSubGrade;
use App\Models\CatalogTokenMap;
use App\Models\CatalogTrim;
use App\Support\Taxonomy\TaxonomyCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CatalogImport extends Command
{
    protected $signature = 'catalog:import
        {--file= : Absolute path to encar_taxonomy_raw.json (default: ../analysis/encar_taxonomy_raw.json)}
        {--fresh : Truncate tables before import}
        {--skip-generations : Skip seeding built-in generation codes}
        {--skip-tokens : Skip seeding built-in token maps}
        {--skip-trims : Skip seeding built-in catalog_trims}';

    protected $description = 'Import encar_taxonomy_raw.json and seed known generation codes into catalog tables';

    /**
     * Seed data for catalog_token_maps.
     * null mapped_value = exclusion list (token matches the pattern but is NOT that thing).
     * string mapped_value = canonical value (token → value).
     */
    private const TOKEN_MAP_SEED = [
        // ── Fuel type detection ──────────────────────────────────────────
        'fuel' => [
            '디젤' => 'diesel', 'diesel' => 'diesel', '경유' => 'diesel',
            '가솔린' => 'gasoline', 'gasoline' => 'gasoline', 'petrol' => 'gasoline', '휘발유' => 'gasoline',
            'lpi' => 'lpg', 'lpe' => 'lpg', 'lpg' => 'lpg', '바이퓨얼' => 'lpg',
            'hev' => 'hybrid', '하이브리드' => 'hybrid', 'hybrid' => 'hybrid',
            'phev' => 'plugin_hybrid', 'plug-in' => 'plugin_hybrid',
            'ev' => 'electric', '전기' => 'electric', 'bev' => 'electric',
            '수소' => 'hydrogen', 'fcev' => 'hydrogen',
            'mhev' => 'mild_hybrid',
            'cng' => 'cng',
            '일렉트릭' => 'electric',  // Korean word for "electric" (전기 already above)
        ],
        // ── Drive type detection ─────────────────────────────────────────
        'drive' => [
            '2wd' => 'fwd', 'fwd' => 'fwd', 'ff' => 'fwd',
            '4wd' => 'awd', 'awd' => 'awd', '4x4' => 'awd',
            '콰트로' => 'awd', 'quattro' => 'awd',
            'xdrive' => 'awd',      // BMW
            '4matic' => 'awd',      // Mercedes-Benz
            '4tronic' => 'awd',     // Volvo
            'htrac' => 'awd',       // Hyundai HTRAC
            'e-htrac' => 'awd',     // Hyundai e-HTRAC (hybrid)
            'e-four' => 'awd',      // Toyota hybrid
            'e-awd' => 'awd',       // Hyundai/Kia EV AWD (아이오닉5, EV6, GV60)
            '4matic+' => 'awd',     // Mercedes-Benz AMG 4MATIC+ (GLE53, E53, CLA45…)
            's-awc' => 'awd',       // Mitsubishi Super All Wheel Control
            '사륜구동' => 'awd',    // Korean "four-wheel drive"
            '전륜구동' => 'fwd',    // Korean "front-wheel drive"
            '후륜구동' => 'rwd',    // Korean "rear-wheel drive"
            '이륜구동' => 'fwd',    // Korean "two-wheel drive" (colloquially means FWD)
            'sdrive' => 'rwd', 'rwd' => 'rwd', 'fr' => 'rwd',
            '4모션' => 'awd',   // VW 4MOTION Korean rendering
            'all4'  => 'awd',   // MINI ALL4 all-wheel drive
        ],
        // ── Body type detection ──────────────────────────────────────────
        'body' => [
            '쿠페' => 'coupe', 'coupe' => 'coupe',
            '카브리올레' => 'convertible', '컨버터블' => 'convertible',
            'cabriolet' => 'convertible', 'convertible' => 'convertible',
            '로드스터' => 'convertible', 'roadster' => 'convertible',
            '왜건' => 'wagon', 'wagon' => 'wagon', 'estate' => 'wagon', '투어링' => 'wagon',
            '해치백' => 'hatchback', '5도어' => 'hatchback', '3도어' => 'hatchback', 'hatchback' => 'hatchback',
            '4도어' => 'sedan',    // 4-door annotation on sedan/SUV listings
            '2도어' => 'coupe',    // 2-door annotation on coupe listings
            '세단' => 'sedan', 'sedan' => 'sedan', 'saloon' => 'sedan',
            '픽업' => 'pickup', 'pickup' => 'pickup',
            '밴' => 'van', 'van' => 'van',
            '리무진' => 'limousine',
            '하이리무진' => 'limousine',  // high-roof limousine (Starex/Staria variants)
            '어린이보호차' => 'school_bus', // child protection vehicle (school bus conversion)
            '앰뷸런스' => 'ambulance',     // ambulance conversion
            '캠핑카' => 'camper',           // camper/RV conversion
            '그란쿠페' => 'coupe',          // Gran Coupe (BMW 4/6/8시리즈 그란쿠페)
            '그란카브리오' => 'convertible', // Gran Cabrio (Maserati)
            '스포트백' => 'hatchback',       // Sportback (Audi A5, VW Arteon)
            '카브리오' => 'convertible',     // Cabrio short form (MINI, Fiat)
            'suv' => 'suv',
            '크로스오버' => 'crossover',    // Korean word for crossover (Chevrolet Trax Crossover)
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
        // mapped_value = null (token key IS the count: 'v8' → 8 → lots.cylinders).
        // Layer 2 parses the int directly: preg_match('/^[a-z](\d+)$/', 'v8') → 8
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
            // ── Standalone diesel/petrol inline suffix ───────────────────────
            // BMW/MB write engine suffix as separate token: "520 d xDrive", "E220 d"
            // Single-char filter in extractTrimKr already handles this, but explicit is better.
            'd' => null,
            // ── Mercedes-AMG sub-brand badge ──────────────────────────────────
            // Standalone "AMG" token after grade_spec_code strips model code (e.g. "G63 AMG")
            'amg' => null,
            // ── Audi power class standalone numbers ──────────────────────────
            // "45 TFSI 프리미엄" → TFSI stripped by engine_family, "45" stays without this
            '35' => null, '40' => null, '45' => null, '50' => null, '55' => null, '60' => null,
            // ── Mercedes-AMG power class standalone numbers ───────────────────
            '43' => null,   // e.g. AMG GT 43, GLE 43 (CLS53/GLE53 already seeded above)
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
            'c43' => null, 'c450' => null, 'c63' => null,
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
            's580' => null, 's580e' => null, 's580l' => null,
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
            'eqe300' => null, 'eqe350' => null, 'eqe500' => null,  // EQE 300 / 350 / 500
            'eqe53'  => null,  // Mercedes-AMG EQE 53
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
            '518d' => null, '520d' => null, '520e' => null, '520i' => null,
            '523d' => null, '525d' => null, '525i' => null,
            '528d' => null, '528i' => null, '530d' => null, '530e' => null, '530i' => null,
            '535d' => null, '535i' => null, '540d' => null, '540i' => null,
            '545e' => null, '550e' => null, '550i' => null, 'm550d' => null, 'm550i' => null,
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
            '20i' => null, '25i' => null, '30i' => null, '30d' => null,
            '35i' => null, '40i' => null, '40d' => null, '50i' => null,
            // BMW short PHEV/diesel codes (newer short-badge format: "30e M 스포츠", "18d xLine")
            '18d' => null, '18i' => null, '20e' => null, '25e' => null, '30e' => null,
            '40e' => null, '45e' => null, '50e' => null, '60e' => null,
            // BMW bare power-class numbers (newer catalog format: "20 M 스포츠", "30 럭셔리")
            '18' => null, '20' => null, '25' => null, '30' => null, '40' => null,
            '120' => null, '220' => null, '228' => null,
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
            'edrive40' => null, 'edrive50' => null,
            // BMW M-Performance powertrain codes (X5/X6/X7 M50i, iX M60, i4 M50 etc.)
            'm50' => null, 'm50i' => null, 'm50d' => null, 'm60' => null, 'm60i' => null, 'm60d' => null,
            // ── Audi ─────────────────────────────────────────────────────────
            '30tfsi' => null, '35tfsi' => null, '40tfsi' => null, '45tfsi' => null,
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
            'l3.5' => null, // Staria L3.5 long-body 3.5L variant code
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
            'lwb'  => null,  // Long Wheelbase (Range Rover LWB, Genesis G90 LWB)
            'ab'   => null,  // Range Rover Autobiography short code (D350 AB HSE etc.)
            // ── Jaguar ────────────────────────────────────────────────────────
            '20d' => null, '25t' => null, '30d' => null,  // older Ingenium codes (F-Pace, XE, XF)
            // ── Lexus ─────────────────────────────────────────────────────────
            '250h' => null, '300h' => null, '350h' => null,  // UX250h, ES300h, NX350h etc.
            '450h' => null, '450h+' => null, '500h' => null,
            // 700h shared with Kia above
            // ── Tesla ─────────────────────────────────────────────────────────
            '75d' => null, '90d' => null, '100d' => null,  // Model S/X older grade designators
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
            // ── EV range / battery spec tokens ────────────────────────────────
            // Appear as leading tokens in EV grade strings (Ioniq5/6, EV6, Tesla, Polestar)
            '롱레인지' => null,             // Long Range
            '익스텐디드레인지' => null,      // Extended Range
            '스탠다드레인지' => null,        // Standard Range (compound form)
            '싱글모터' => null,             // Single Motor
            '듀얼모터' => null,             // Dual Motor
            // ── Option package suffixes (appear AFTER trim name — strip to expose trim) ─
            // e.g. "프레스티지 기술패키지" → strip "기술패키지" → "프레스티지"
            '패키지' => null,              // fallback orphan token
            '기술패키지' => null,
            '컴포트패키지' => null,
            '프리미엄패키지' => null,
            '파노라마패키지' => null,
            '스마트패키지' => null,
            '드라이빙어시스턴스패키지' => null,
            '스포츠패키지' => null,
            // ── Vehicle purpose / sales channel annotations (appear without parens in some listings) ──
            '장애인용' => null,    // disabled person vehicle
            '렌터카' => null,      // rental car
            '특장업체' => null,    // special bodywork converter
            '수출형' => null,      // export model
            '구조변경' => null,    // structural modification
            '택시형' => null,      // taxi model
        ],
        // ── Engine displacement tokens stripped from grade_kr ─────────────
        // mapped_value = null (token key IS the numeric value).
        // Layer 2 parses the float directly from the token: '2.0t' → 2.0 → lots.engine_volume
        'grade_engine_vol' => [
            // Plain decimal volumes common in Korean market
            '1.0' => null, '1.2' => null, '1.4' => null, '1.5' => null,
            '1.6' => null, '1.7' => null, '1.8' => null, '2.0' => null, '2.2' => null,
            '2.4' => null, '2.5' => null, '2.7' => null, '3.0' => null,
            '3.3' => null, '3.5' => null, '3.6' => null, '3.8' => null, '4.0' => null,
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
            // Additional volumes seen in Korean market data
            '0.6' => null,  // kei-car / micro (Spark, Alto, N-BOX)
            '2.9' => null,  // Maserati, SsangYong (2.9T biturbo)
            '3.5l' => null, // L-suffix form (e.g. F150 3.5L)
            '3.7' => null,  // Infiniti, older Jeep Grand Cherokee
            '4.8' => null,  // BMW X5 4.8i
            '5.2' => null,  // Lamborghini Huracan (5.2 NA)
            '5.3' => null,  // GM 5.3L V8 (Silverado, Tahoe, Suburban)
            '5.7' => null,  // Dodge Hemi, older Chevrolet
            '6.7' => null,  // Bentley Mulsanne 6.75 short form + Ford PowerStroke
        ],
        // ── Korean generation label tokens: 1세대, 2세대 … stripped from grade_kr ─
        'grade_gen_label' => [
            '1세대' => null, '2세대' => null, '3세대' => null, '4세대' => null,
            '5세대' => null, '6세대' => null, '7세대' => null, '8세대' => null,
        ],
        // ── Korean seat-count tokens stripped from grade_kr ───────────────
        'grade_seat' => [
            '2인승' => null, '4인승' => null,  // 2-seaters (sports/kei) and 4-seat configs
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
            // Supreme / Premium / Luxury / Luxury+ / Executive / F Sport / Takumi
            '슈프림' => null,           // Supreme (older NX/RX/ES lower trim)
            '럭셔리플러스' => null,      // Luxury Plus
            '타쿠미' => null,           // Takumi (LC top trim)
            'f스포츠' => null,          // F Sport (NX, RX, ES, LC)
            '버사체' => null,
            // ── Toyota Korea ──────────────────────────────────────────────────
            // LE / SE (already above), XLE / XSE (Prius, Camry, RAV4, Highlander)
            'xle' => null, 'xse' => null,
            '그랜드투어링' => null,
            // ── Kia EV-specific trims ─────────────────────────────────────────
            '어스' => null,             // Earth (EV6 top trim)
            // ── Porsche Korea ─────────────────────────────────────────────────
            // 911: Carrera / GTS / Turbo / Targa / Spyder / GT3 / GT4
            '카레라' => null,
            '타르가' => null,
            '스파이더' => null,          // open-top Porsche / Ferrari / Lambo
            // ── Ferrari Korea ─────────────────────────────────────────────────
            // 488 GTB / 488 GTS / F8 Tributo / Portofino / SF90 Stradale
            'gtb' => null, 'gts' => null,
            '트리부토' => null, '포르토피노' => null, '스트라달레' => null,
            // ── Lamborghini Korea ─────────────────────────────────────────────
            // Huracan: EVO / Performante / STO   Aventador: S / SVJ / Ultimae
            '퍼포만테' => null, '슈퍼레제라' => null,
            'evo' => null, 'sto' => null, 'svj' => null, 'ultimae' => null,
            // ── Lincoln Korea ─────────────────────────────────────────────────
            '리저브' => null,           // Reserve
            '블랙레이블' => null,        // Black Label
            // ── Mercedes-Benz special editions ───────────────────────────────
            '나이트에디션' => null,
            // ── General cross-brand trim words ───────────────────────────────
            '플러스' => null,   // "스포츠 플러스", "인스크립션 플러스"
            '스페셜' => null,   // "모던 스페셜"
            '에디션' => null,   // "퍼스트 에디션", "30주년 에디션"
            '리미티드' => null,
        ],
        // ── Korean model name marketing prefixes — stripped to get canonical name ─
        // Sorted by specificity: multi-word before single-word (handled at runtime)
        'model_prefix' => [
            '디 올 뉴'    => null,  // "The All New" (Kia/Hyundai EV marketing — 디 올 뉴 니로)
            '어메이징 뉴' => null,  // "Amazing New" (older facelift marketing)
            '더 넥스트' => null,  // "The Next" (Chevrolet 더 넥스트 스파크/말리부/크루즈)
            '더 뉴'     => null,  // "The New" facelift
            '올 뉴'     => null,  // "All New" full redesign
            '베리 뉴'   => null,  // "Very New" (Renault Samsung SM3/QM3 older marketing)
            '올뉴'      => null,  // "All New" (no-space variant)
            '더'        => null,  // standalone "더" (older Hyundai/Kia)
            '뉴'        => null,  // "New" (older models)
            '신형'      => null,  // "New model" / revised
            // Single-word leftovers from multi-word prefixes (token map is per-token, not phrase)
            '올'        => null,  // trailing from "올 뉴" (All New) — 올뉴 catches no-space variant
            '디'        => null,  // trailing from "디 올 뉴" (The All New)
            '넥스트'    => null,  // trailing from "더 넥스트" (The Next, Chevrolet)
            '라이즈'    => null,  // trailing from "뉴 라이즈" (Sonata New Rise facelift)
            // Brand name leaking into model strings
            '기아'      => null,  // e.g. "더 뉴 기아 레이 시그니처"
            '그랜드'    => null,  // e.g. "그랜드 스타렉스" when model is stored as "스타렉스"
            '뷰티풀'    => null,  // "Beautiful Korando" (4th gen Korando marketing prefix)
            // Single-word leftovers from multi-word marketing labels that survive "뉴" stripping
            '어메이징'      => null,  // from "어메이징 뉴" (Amazing New) — "뉴" already stripped
            '베리'          => null,  // from "베리 뉴" (Very New) — "뉴" already stripped
            // Variant/sub-brand suffixes appended to model names
            '어반'          => null,  // Kia Morning Urban suffix (stripped AFTER earlier trim match)
            '일렉트리파이드' => null,  // Genesis Electrified G80/GV70 sub-brand prefix
            'ix'            => null,  // Hyundai Tucson ix (2nd gen) marketing suffix
            '올스페이스'    => null,  // VW Tiguan Allspace model suffix
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
            '4matic' => null, '4matic+' => null, '블루텍' => null, 'gte' => null, 'lpli' => null, '4tronic' => null,
            'hemi' => null,  // Chrysler/Dodge Hemispherical engine branding
            'skyactiv' => null, 'skyactiv-g' => null, 'skyactiv-d' => null, 'skyactiv-x' => null,
            'vtec' => null, 'cvvt' => null, 'dohc' => null, 'sohc' => null,
            'tce' => null,   // Renault TCe (Turbo petrol: TCe 130, TCe 260)
            'hdi' => null, 'b-hdi' => null, 'e-hdi' => null, 'bluehdi' => null,  // PSA/Stellantis diesel
            '터보'    => null,   // Korean powertrain descriptor (Porsche/BMW Turbo trim matched first via CATALOG_TRIMS)
            'vvt'     => null,   // Hyundai VVT engine technology
            'dci'     => null,   // Renault dCi diesel injection
            'e-tech'  => null,   // Renault E-TECH hybrid system
            'e-s/c'   => null,   // Genesis/Hyundai electrified super/turbo combustion notation
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
     * Seed data for catalog_trims.
     * Format: trim_kr => [trim_en|null, make_en (''=>universal), priority]
     *
     * priority 100 = brand-specific (high confidence)
     * priority  90 = brand-specific but common across variants
     * priority  80 = universal (used by many brands)
     */
    /**
     * Each entry: [trim_kr, trim_en|null, make_en ('' = universal), priority]
     * List format (not associative) so the same trim_kr can appear for multiple makes.
     */
    private const CATALOG_TRIMS_SEED = [
        // ── Universal (make_en = '') ──────────────────────────────────────
        ['프레스티지',          'Prestige',         '',              90],
        ['프리미엄',            'Premium',          '',              90],
        ['노블레스',            'Noblesse',         '',              90],
        ['시그니처',            'Signature',        '',              90],
        ['캘리그래피',          'Calligraphy',      '',              90],
        ['인스퍼레이션',        'Inspiration',      '',              90],
        ['익스클루시브',        'Exclusive',        '',              90],
        ['르블랑',              'Le Blanc',         '',              90],
        ['모던',                'Modern',           '',              80],
        ['스마트',              'Smart',            '',              80],
        ['스타일',              'Style',            '',              80],
        ['럭셔리',              'Luxury',           '',              80],
        ['디럭스',              'Deluxe',           '',              80],
        ['스탠다드',            'Standard',         '',              80],
        ['트렌디',              'Trendy',           '',              80],
        ['프리미어',            'Premier',          '',              80],
        ['마스터즈',            'Masters',          '',              80],
        ['마스터피스',          'Masterpiece',      '',              80],
        ['플래티넘',            'Platinum',         '',              80],
        ['그래비티',            'Gravity',          '',              80],
        ['어드밴스드',          'Advanced',         '',              80],
        ['아너스',              'Honors',           '',              80],
        ['하이클래스',          'Hi-Class',         '',              80],
        ['트렌드',              'Trend',            '',              80],
        ['스포츠',              'Sport',            '',              80],
        ['아웃도어',            'Outdoor',          '',              80],
        ['리미티드',            'Limited',          '',              80],
        ['스페셜',              'Special',          '',              80],
        ['베이직',              'Basic',            '',              80],
        ['어반',                'Urban',            '',              80],
        ['액티브',              'Active',           '',              80],
        ['레드라인',            'RedLine',          '',              80],
        ['어드벤처',            'Adventure',        '',              80],
        ['VIP',                 'VIP',              '',              80],
        ['헤리티지',            'Heritage',         '',              80],
        ['인스파이어',          'Inspire',          '',              80],
        ['어스',                'Earth',            '',              80],
        // Hyundai/Kia appearance packages
        ['N Line',              'N Line',           '',              90],
        ['N 라인',              'N Line',           '',              90],
        ['GT-Line',             'GT-Line',          '',              90],
        ['GT 라인',             'GT-Line',          '',              90],
        ['X-Line',              'X-Line',           '',              90],
        ['X 라인',              'X-Line',           '',              90],
        // Renault Korea grades
        ['RE',                  'RE',               '',              90],
        ['LE',                  'LE',               '',              90],
        ['SE',                  'SE',               '',              90],
        ['PE',                  'PE',               '',              90],
        ['RE Plus',             'RE Plus',          '',              90],
        ['LE Plus',             'LE Plus',          '',              90],
        ['인텐스',              'Intense',          '',              80],
        ['라이프',              'Life',             '',              80],
        ['HIGH',                'HIGH',             '',              80],
        ['퍼스트',              'First',            '',              80],
        ['노블레스 라이트',     'Noblesse Lite',    '',              90],
        ['시그니처 블랙',       'Signature Black',  '',              90],
        ['베스트 셀렉션',       'Best Selection',   '',              80],
        ['알칸타라 에디션',     'Alcantara Edition','',              80],
        ['퀀텀',                'Quantum',          '',              80],
        // Hyundai trims in trim_whitelist but missing from catalog
        ['하이테크',            'Hi-Tech',          '',              80],
        ['글로시',              'Glossy',           '',              80],
        ['파퓰러',              'Popular',          '',              80],
        ['밸류',                'Value',            '',              80],
        ['블랙잉크',            'Black Ink',        '',              80],
        ['블랙익스테리어',      'Black Exterior',   '',              80],
        ['H-Pick',              'H-Pick',           '',              80],
        // Genesis trims
        ['디스팅션',            'Distinction',      '',              90],
        ['임프레션',            'Impression',       '',              80],
        // Compound Prestige variants (priority ≥ 90 so they match before bare '프레스티지')
        ['프레스티지 플러스',   'Prestige Plus',    '',              90],  // Santa Fe MX5 2025 bestseller
        ['프레스티지 블랙',     'Prestige Black',   '',              90],  // Genesis G80/G90/GV80/GV80쿠페
        ['프레스티지 컬렉션',   'Prestige Collection', '',           90],  // Genesis G90 top trim
        // EV base trim (Kia EV6 / Ioniq 5/6 entry-level)
        ['에어',                'Air',              'Kia',           100],
        ['에어',                'Air',              'Hyundai',       100],
        // No-space variants (some lots omit space: "N라인" not "N 라인")
        ['N라인',               'N Line',           '',              90],
        ['GT라인',              'GT-Line',          '',              90],
        ['엑스라인',            'X-Line',           '',              90],
        // Honda Korea — prevents 투어링/Trail Sport being misread as body=wagon
        ['투어링',              'Touring',          'Honda',         100],  // Accord HEV 투어링
        ['트레일스포츠',        'Trail Sport',      'Honda',         100],  // CR-V 2026
        ['EX-L',                'EX-L',             'Honda',         100],

        // ── Chevrolet Korea ───────────────────────────────────────────────
        ['LS',                  'LS',               'Chevrolet',     100],
        ['LT',                  'LT',               'Chevrolet',     100],
        ['LTZ',                 'LTZ',              'Chevrolet',     100],
        ['ACTIV',               'ACTIV',            'Chevrolet',     100],
        ['Premier',             'Premier',          'Chevrolet',     100],

        // ── BMW ───────────────────────────────────────────────────────────
        ['M 스포츠',            'M Sport',          'BMW',           100],
        ['M 스포츠 프로',       'M Sport Pro',      'BMW',           100],
        ['M 스포츠 플러스',     'M Sport Plus',     'BMW',           100],
        ['M 스포츠 패키지',     'M Sport Package',  'BMW',           100],
        ['M 퍼포먼스',          'M Performance',    'BMW',           100],
        ['M Sport',             'M Sport',          'BMW',           100],
        ['M Sport Pro',         'M Sport Pro',      'BMW',           100],
        ['xLine',               'xLine',            'BMW',           100],
        ['X라인',               'xLine',            'BMW',           100],
        ['Luxury Line',         'Luxury Line',      'BMW',           100],
        ['럭셔리 라인',         'Luxury Line',      'BMW',           100],
        ['Sport Line',          'Sport Line',       'BMW',           100],
        ['스포트라인',          'Sport Line',       'BMW',           100],
        ['Advantage',           'Advantage',        'BMW',           100],
        ['어드밴티지',          'Advantage',        'BMW',           100],
        ['퓨어',                'Pure',             'BMW',           100],
        ['이그제큐티브',        'Executive',        'BMW',           100],
        ['컴포트',              'Comfort',          'BMW',           100],
        ['온라인 익스클루시브', 'Online Exclusive', 'BMW',           100],
        ['럭셔리 플러스',       'Luxury Plus',      'BMW',           100],
        ['Luxury Plus',         'Luxury Plus',      'BMW',           100],
        ['조이',                'Joy',              'BMW',           100],  // F20 1-Series entry trim (Joy/Sport/Urban/Luxury)
        ['섀도우',              'Shadow',           'BMW',           100],  // Shadow Edition (M sport visual pkg)
        ['컴페티션',            'Competition',      'BMW',           100],  // M2/M3/M4 Competition

        // ── Mercedes-Benz ─────────────────────────────────────────────────
        ['아방가르드',          'Avantgarde',       'Mercedes-Benz', 100],
        ['Avantgarde',          'Avantgarde',       'Mercedes-Benz', 100],
        ['AMG Line',            'AMG Line',         'Mercedes-Benz', 100],
        ['AMG 라인',            'AMG Line',         'Mercedes-Benz', 100],
        ['엘레강스',            'Elegance',         'Mercedes-Benz', 100],
        ['Elegance',            'Elegance',         'Mercedes-Benz', 100],
        ['Style',               'Style',            'Mercedes-Benz', 100],
        ['Night Edition',       'Night Edition',    'Mercedes-Benz', 100],
        ['나이트에디션',        'Night Edition',    'Mercedes-Benz', 100],
        ['아방가르드 플러스',   'Avantgarde Plus',  'Mercedes-Benz', 100],
        ['Avantgarde Plus',     'Avantgarde Plus',  'Mercedes-Benz', 100],

        // ── Audi ──────────────────────────────────────────────────────────
        ['S-Line',              'S-Line',           'Audi',          100],
        ['S Line',              'S-Line',           'Audi',          100],  // no-hyphen variant
        ['S 라인',              'S-Line',           'Audi',          100],
        ['Dynamic',             'Dynamic',          'Audi',          100],
        ['다이나믹',            'Dynamic',          'Audi',          100],
        ['Technik',             'Technik',          'Audi',          100],
        ['Advanced',            'Advanced',         'Audi',          100],
        ['어드밴스',            'Advanced',         'Audi',          100],
        ['S라인 블랙에디션',    'S-Line Black Edition', 'Audi',      100],
        ['S-Line Black Edition','S-Line Black Edition', 'Audi',      100],

        // ── Volkswagen ────────────────────────────────────────────────────
        ['Progressive',         'Progressive',      'Volkswagen',    100],
        ['프로그레시브',        'Progressive',      'Volkswagen',    100],
        ['Elegance',            'Elegance',         'Volkswagen',    100],
        ['하이라인',            'Highline',         'Volkswagen',    100],
        ['컴포트라인',          'Comfortline',      'Volkswagen',    100],
        ['트렌드라인',          'Trendline',        'Volkswagen',    100],
        ['R-Line',              'R-Line',           'Volkswagen',    100],
        ['R라인',               'R-Line',           'Volkswagen',    100],

        // ── Volvo ─────────────────────────────────────────────────────────
        ['Momentum',            'Momentum',         'Volvo',         100],
        ['모멘텀',              'Momentum',         'Volvo',         100],
        ['Inscription',         'Inscription',      'Volvo',         100],
        ['인스크립션',          'Inscription',      'Volvo',         100],
        ['R-Design',            'R-Design',         'Volvo',         100],
        ['R디자인',             'R-Design',         'Volvo',         100],
        ['Core',                'Core',             'Volvo',         100],
        ['Plus',                'Plus',             'Volvo',         100],
        ['Ultra',               'Ultra',            'Volvo',         100],
        ['얼티밋',              'Ultimate',         'Volvo',         100],
        ['Cross Country',       'Cross Country',    'Volvo',         100],
        ['크로스컨트리',        'Cross Country',    'Volvo',         100],  // Korean rendering
        ['Polestar',            'Polestar',         'Volvo',         100],

        // ── Lexus ─────────────────────────────────────────────────────────
        ['F Sport',             'F Sport',          'Lexus',         100],
        ['F 스포츠',            'F Sport',          'Lexus',         100],
        ['슈프림',              'Supreme',          'Lexus',         100],
        ['타쿠미',              'Takumi',           'Lexus',         100],
        ['럭셔리플러스',        'Luxury Plus',      'Lexus',         100],
        ['Executive',           'Executive',        'Lexus',         100],
        ['버사체',              'Versace',          'Lexus',         100],

        // ── Toyota ────────────────────────────────────────────────────────
        ['XLE',                 'XLE',              'Toyota',        100],
        ['XSE',                 'XSE',              'Toyota',        100],
        ['Touring',             'Touring',          'Toyota',        100],
        ['그랜드투어링',        'Grand Touring',    'Toyota',        100],

        // ── Land Rover ────────────────────────────────────────────────────
        ['HSE',                 'HSE',              'Land Rover',    100],
        ['SE',                  'SE',               'Land Rover',    100],
        ['Autobiography',       'Autobiography',    'Land Rover',    100],
        ['오토바이오그래피',    'Autobiography',    'Land Rover',    100],
        ['SVR',                 'SVR',              'Land Rover',    100],
        ['SVX',                 'SVX',              'Land Rover',    100],
        ['다이나믹',            'Dynamic',          'Land Rover',    100],  // Range Rover Sport / Discovery Dynamic

        // ── Porsche ───────────────────────────────────────────────────────
        ['Carrera',             'Carrera',          'Porsche',       100],
        ['카레라',              'Carrera',          'Porsche',       100],
        ['GTS',                 'GTS',              'Porsche',       100],
        ['Turbo',               'Turbo',            'Porsche',       100],
        ['Turbo S',             'Turbo S',          'Porsche',       100],
        ['4S',                  '4S',               'Porsche',       100],
        ['GT3',                 'GT3',              'Porsche',       100],
        ['GT3 RS',              'GT3 RS',           'Porsche',       100],
        ['GT4',                 'GT4',              'Porsche',       100],
        ['Targa',               'Targa',            'Porsche',       100],
        ['타르가',              'Targa',            'Porsche',       100],
        ['Spyder',              'Spyder',           'Porsche',       100],
        ['스파이더',            'Spyder',           'Porsche',       100],
        ['컴페티션',            'Competition',      'Porsche',       100],
        ['Competition',         'Competition',      'Porsche',       100],

        // ── Lincoln ───────────────────────────────────────────────────────
        ['Reserve',             'Reserve',          'Lincoln',       100],
        ['리저브',              'Reserve',          'Lincoln',       100],
        ['Black Label',         'Black Label',      'Lincoln',       100],
        ['블랙레이블',          'Black Label',      'Lincoln',       100],

        // ── MINI ──────────────────────────────────────────────────────────
        ['JCW',                 'JCW',              'MINI',          100],

        // ── Lamborghini ───────────────────────────────────────────────────
        ['Performante',         'Performante',      'Lamborghini',   100],
        ['퍼포만테',            'Performante',      'Lamborghini',   100],
        ['EVO',                 'EVO',              'Lamborghini',   100],
        ['STO',                 'STO',              'Lamborghini',   100],
        ['SVJ',                 'SVJ',              'Lamborghini',   100],
        ['Ultimae',             'Ultimae',          'Lamborghini',   100],
        ['슈퍼레제라',          'Superleggera',     'Lamborghini',   100],

        // ── Ferrari ───────────────────────────────────────────────────────
        ['GTB',                 'GTB',              'Ferrari',       100],
        ['GTS',                 'GTS',              'Ferrari',       100],
        ['Tributo',             'Tributo',          'Ferrari',       100],
        ['트리부토',            'Tributo',          'Ferrari',       100],

        // ── SsangYong / KG Mobility ───────────────────────────────────────
        ['VX',                  'VX',               'KG Mobility',   100],
        ['DX',                  'DX',               'KG Mobility',   100],
        ['LX',                  'LX',               'KG Mobility',   100],  // Tivoli trim
        ['IX',                  'IX',               'KG Mobility',   100],  // Tivoli Air trim
        ['EX',                  'EX',               'KG Mobility',   100],  // Korando/Tivoli
        ['MX',                  'MX',               'KG Mobility',   100],  // Korando trim
        ['RX5',                 'RX5',              'KG Mobility',   100],  // Rexton grade
        ['RX7',                 'RX7',              'KG Mobility',   100],  // Rexton grade (confirmed namu.wiki)
        ['CX7',                 'CX7',              'KG Mobility',   100],  // Korando Sports grade
        ['기어',                'Gear',             'KG Mobility',   100],  // Tivoli Armor trim
        ['기어 플러스',         'Gear Plus',        'KG Mobility',   100],  // Tivoli Armor trim
        ['기어 에디션',         'Gear Edition',     'KG Mobility',   100],  // Tivoli Armor trim
        // Tivoli Era-1 short code (TX = base, VX/DX/LX already seeded)
        ['TX',                  'TX',               'KG Mobility',   100],
        ['AX',                  'AX',               'KG Mobility',   100],
        ['RX',                  'RX',               'KG Mobility',   100],
        ['KX',                  'KX',               'KG Mobility',   100],
        // Tivoli Era-2 (V-series standard, A-series Air)
        ['V1',                  'V1',               'KG Mobility',   100],
        ['V3',                  'V3',               'KG Mobility',   100],
        ['V5',                  'V5',               'KG Mobility',   100],
        ['V7',                  'V7',               'KG Mobility',   100],
        ['A1',                  'A1',               'KG Mobility',   100],
        ['A3',                  'A3',               'KG Mobility',   100],
        ['A5',                  'A5',               'KG Mobility',   100],
        ['A7',                  'A7',               'KG Mobility',   100],
        ['업비트',              'Upbeat',           'KG Mobility',   100],
        // Rexton / Korando old grade hierarchy
        ['CVS',                 'CVS',              'KG Mobility',   100],
        ['CVT',                 'CVT',              'KG Mobility',   100],
        ['CVX',                 'CVX',              'KG Mobility',   100],
        ['C3 플러스',           'C3 Plus',          'KG Mobility',   100],
        ['C5',                  'C5',               'KG Mobility',   100],
        ['C7',                  'C7',               'KG Mobility',   100],
        ['R-플러스',            'R Plus',           'KG Mobility',   100],
        // Appearance / special editions
        ['샤이니',              'Shiny',            'KG Mobility',   100],
        ['딜라이트',            'Delight',          'KG Mobility',   100],
        ['딜라이트 플러스',     'Delight Plus',     'KG Mobility',   100],
        ['판타스틱',            'Fantastic',        'KG Mobility',   100],
        ['블랙엣지',            'Black Edge',       'KG Mobility',   100],
        ['샤토',                'Chateau',          'KG Mobility',   100],
        ['CX5',                 'CX5',              'KG Mobility',   100],  // Korando Sports 2WD variant
        // Torres EVX grade hierarchy
        ['E5',                  'E5',               'KG Mobility',   100],
        ['E7',                  'E7',               'KG Mobility',   100],
        // Extreme trim (Tivoli Air / Korando)
        ['익스트림',            'Extreme',          'KG Mobility',   100],
        ['익스트림 스포츠',     'Extreme Sport',    'KG Mobility',   100],

        // ── MINI (official U25 3rd-gen Countryman trims confirmed Wikipedia) ─
        ['에센셜',              'Essential',        'MINI',          100],
        ['클래식',              'Classic',          'MINI',          100],
        ['페이버드',            'Favoured',         'MINI',          100],
        ['Essential',           'Essential',        'MINI',          100],
        ['Classic',             'Classic',          'MINI',          100],
        ['Favoured',            'Favoured',         'MINI',          100],
        ['Mid',                 'Mid',              'MINI',          100],  // MINI mid-range trim label
        ['런치팩',              'Launch Pack',      'MINI',          100],  // Launch Pack (differentiating variant — keep)
        ['클래식 플러스',       'Classic Plus',     'MINI',          100],
        ['클래식 런치팩',       'Classic Launch Pack', 'MINI',       100],
        ['사이드워크',          'Sidewalk',         'MINI',          100],  // Convertible Sidewalk edition
        // Special editions (named after London places, colours, themes)
        ['프롤로그 에디션',     'Prologue Edition', 'MINI',          100],
        ['파크레인 에디션',     'Park Lane Edition','MINI',          100],
        ['레솔루트 에디션',     'Resolute Edition', 'MINI',          100],
        ['로즈우드 에디션',     'Rosewood Edition', 'MINI',          100],
        ['멀티톤 에디션',       'Multitone Edition','MINI',          100],
        ['메이필드 에디션',     'Mayfield Edition', 'MINI',          100],
        ['세븐 에디션',         'Seven Edition',    'MINI',          100],
        ['블랙 수트 에디션',    'Black Suit Edition','MINI',         100],
        ['브릭레인 에디션',     'Brick Lane Edition','MINI',         100],
        ['킹스로드 에디션',     'Kings Road Edition','MINI',         100],
        ['피카딜리 에디션',     'Piccadilly Edition','MINI',         100],
        ['씨사이드 에디션',     'Seaside Edition',  'MINI',          100],
        ['언차티드 에디션',     'Uncharted Edition','MINI',          100],
        ['언테임드 에디션',     'Untamed Edition',  'MINI',          100],
        ['하이랜드 에디션',     'Highland Edition', 'MINI',          100],
        ['언톨드 에디션',       'Untold Edition',   'MINI',          100],
        ['파이널 에디션',       'Final Edition',    'MINI',          100],
        ['젠 Z 에디션',         'Gen Z Edition',    'MINI',          100],
        ['젠 ZE 에디션',        'Gen ZE Edition',   'MINI',          100],

        // ── Tesla Korea ──────────────────────────────────────────────────────
        ['스탠다드 레인지',        'Standard Range',         'Tesla',  100],
        ['스탠다드 레인지 플러스', 'Standard Range Plus',     'Tesla',  100],
        ['롱레인지',               'Long Range',              'Tesla',  100],
        ['퍼포먼스',               'Performance',             'Tesla',  100],

        // ── Renault Korea ─────────────────────────────────────────────────────
        ['에스프리 알핀',       'Esprit Alpine',    'Renault',       100],  // SM6/Arkana Alpine trim
        ['노바 L',              'Nova L',           'Renault',       100],  // SM7 Nova series trim

        // ── Hyundai additional ────────────────────────────────────────────────
        ['하이리무진',          'High Limousine',   'Hyundai',       100],  // Grand Starex 하이리무진 grade
        ['라운지',              'Lounge',           'Hyundai',       100],  // Staria 라운지 trim
        ['투어러',              'Tourer',           'Hyundai',       100],  // Staria 투어러 (avoid body=wagon)
        ['케어플러스',          'Care Plus',        'Hyundai',       100],  // Sonata 케어플러스
        ['디 에센셜',           'The Essential',    'Hyundai',       100],  // Casper entry trim
        ['N',                   'N',                'Hyundai',       100],  // Ioniq 5 N / N-branded performance

        // ── Kia additional ────────────────────────────────────────────────────
        ['올 GT 플러스',        'All GT Plus',      'Kia',           100],  // K3 top trim
        ['올 GT',               'All GT',           'Kia',           100],  // K3 trim
        ['이그제큐티브',        'Executive',        'Kia',           100],  // K9 이그제큐티브

        // ── Genesis ───────────────────────────────────────────────────────────
        ['이그제큐티브',        'Executive',        'Genesis',       100],  // G90 최고 등급

        // ── Lexus additional ──────────────────────────────────────────────────
        ['이그제큐티브',        'Executive',        'Lexus',         100],  // ES/LS Korean rendering

        // ── Mercedes-Benz additional ──────────────────────────────────────────
        ['프로그레시브',        'Progressive',      'Mercedes-Benz', 100],  // A/C/E-Class trim
        ['Progressive',         'Progressive',      'Mercedes-Benz', 100],
        ['일렉트릭 아트',       'Electric Art',     'Mercedes-Benz', 100],  // EQS/EQE trim

        // ── BMW additional ────────────────────────────────────────────────────
        ['프로',                'Pro',              'BMW',           100],  // i4 M50 프로, i5 M60 프로
        ['디자인 퓨어 엑셀런스', 'Design Pure Excellence', 'BMW',     100],  // 7-Series top trim

        // ── Porsche (Korean rendering of performance trims) ───────────────────
        ['터보',                'Turbo',            'Porsche',       100],  // Korean 터보 = Turbo trim
        ['터보 S',              'Turbo S',          'Porsche',       100],  // Korean 터보 S

        // ── Jeep ─────────────────────────────────────────────────────────────
        ['론지튜드',            'Longitude',        'Jeep',          100],  // Renegade / Compass
        ['론지튜드 하이',       'Longitude High',   'Jeep',          100],  // Renegade 최상위 트림
        ['Longitude',           'Longitude',        'Jeep',          100],
        ['Longitude High',      'Longitude High',   'Jeep',          100],

        // ── Kia EV-specific (higher priority than universal '어스') ─────────
        ['어스',                'Earth',            'Kia',           100],

        // ── Compound edition trims — priority 90 so they beat bare '스페셜'/'에디션' at 80 ──
        ['스페셜 에디션',       'Special Edition',      '',           90],
        ['퍼스트 에디션',       'First Edition',        '',           90],
        ['블랙 에디션',         'Black Edition',        '',           90],
        ['컴포트 에디션',       'Comfort Edition',      '',           90],
        ['온라인 에디션',       'Online Edition',       '',           90],
        ['카본 에디션',         'Carbon Edition',       '',           90],
        ['런치 에디션',         'Launch Edition',       '',           90],
        ['퍼포먼스 에디션',     'Performance Edition',  '',           90],
        ['시티팝 에디션',       'City Pop Edition',     '',           90],
        // Compound trims that beat shorter component trims ──────────────────
        ['노블레스 스페셜',     'Noblesse Special',     '',           95],  // Kia Carnival 노블레스 스페셜
        ['밸류 플러스',         'Value Plus',           '',           90],  // Avante 밸류 플러스
        ['플래티넘 플러스',     'Platinum Plus',        '',           90],
        ['테크노 플러스',       'Techno Plus',          '',           90],  // Renault Korea
        ['ST-라인',             'ST-Line',              '',           90],  // Ford/Renault ST-Line
        ['프라임',              'Prime',                '',           90],  // 싼타페 더 프라임, Toyota Prime
        ['GT',                  'GT',                   '',           90],  // 6시리즈 GT, 머스탱 GT, K5 GT

        // ── Universal standard trims (priority 80) ──────────────────────────
        ['초이스',              'Choice',               '',           80],  // 그랜저 프리미엄 초이스
        ['프리미에르',          'Première',             '',           80],  // Renault/Citroën Korean
        ['미드나이트 블랙',     'Midnight Black',       '',           80],
        ['퍼펙트 블랙',         'Perfect Black',        '',           80],
        ['블랙라벨',            'Black Label',          '',           80],

        // ── Universal mid-tier trims (priority 80) ───────────────────────────
        ['컴페티션',            'Competition',          '',           80],  // universal; Porsche/BMW already have 100
        ['AMG',                 'AMG',                  'Mercedes-Benz', 100], // standalone AMG badge (G63 AMG etc.)
        ['80주년 에디션',       '80th Anniversary',     '',           70],  // 80th anniversary special

        // ── Universal basic trims (priority 70) ──────────────────────────────
        ['에센스',              'Essence',              '',           70],
        ['에센셜',              'Essential',            '',           70],  // universal; MINI has own at 100
        ['아이코닉',            'Iconic',               '',           70],
        ['볼드',                'Bold',                 '',           70],
        ['브릴리언트',          'Brilliant',            '',           70],
        ['엘리트',              'Elite',                '',           70],
        ['최고급형',            'Top Grade',            '',           70],  // older Korean tier name
        ['고급형',              'High Grade',           '',           70],
        ['기본형',              'Base',                 '',           70],

        // ── Kia additional ──────────────────────────────────────────────────
        ['마스터',              'Master',               'Kia',        100], // 모하비 더 마스터
        ['볼드',                'Bold',                 'Kia',        100], // 스포티지 더 볼드
        ['RVIP',                'RVIP',                 'Kia',        100], // K9 RVIP (prevents "VIP" substring hit leaving "R")

        // ── Hyundai additional ───────────────────────────────────────────────
        ['초이스',              'Choice',               'Hyundai',    100], // 그랜저 IG 프리미엄 초이스
        ['파이니스트 에디션',   'Finest Edition',       'Hyundai',    100], // 제네시스 DH G380
        ['카고',                'Cargo',                'Hyundai',    100], // 스타리아 카고

        // ── Volvo additional ─────────────────────────────────────────────────
        ['얼티메이트 브라이트', 'Ultimate Bright',      'Volvo',      100], // XC60/XC90 colour edition
        ['얼티메이트 다크',     'Ultimate Dark',        'Volvo',      100],
        ['울트라 브라이트',     'Ultra Bright',         'Volvo',      100], // older naming variant
        ['울트라 다크',         'Ultra Dark',           'Volvo',      100],
        ['R-디자인',            'R-Design',             'Volvo',      100], // hyphenated variant of 'R디자인'

        // ── Land Rover additional ────────────────────────────────────────────
        ['HSE 다이나믹',        'HSE Dynamic',          'Land Rover', 100], // 레인지로버 스포츠 HSE 다이나믹
        ['HSE Dynamic',         'HSE Dynamic',          'Land Rover', 100],

        // ── Renault Korea additional ─────────────────────────────────────────
        ['네오',                'Neo',                  'Renault Korea', 100], // SM3 네오
        ['노바',                'Nova',                 'Renault Korea', 100], // SM7 Nova facelift sub-brand
        ['플럭스',              'Flux',                 'Renault Korea', 100], // SM6 / QM6 Flux special edition
        ['플럭스',              'Flux',                 'Renault',       100], // same, alternate make_en key

        // ── Renault Korea (fix make_en mismatch — DB stores 'Renault Korea', not 'Renault') ─
        ['에스프리 알핀',       'Esprit Alpine',        'Renault Korea', 100], // SM6/Arkana Alpine trim

        // ── Jeep ─────────────────────────────────────────────────────────────
        ['루비콘',              'Rubicon',              'Jeep',          100], // Wrangler top trim
        ['사하라',              'Sahara',               'Jeep',          100], // Wrangler mid-top trim
        ['오버랜드',            'Overland',             'Jeep',          100], // Grand Cherokee top tier
        ['트레일호크',          'Trailhawk',            'Jeep',          100], // Cherokee/Compass off-road trim

        // ── Tesla ─────────────────────────────────────────────────────────────
        ['롱 레인지',           'Long Range',           'Tesla',         100], // space-variant (롱레인지 already seeded)
        ['플래드',              'Plaid',                'Tesla',         100], // Model S/X/3/Y Plaid

        // ── Porsche ───────────────────────────────────────────────────────────
        ['S',                   'S',                    'Porsche',       100], // Macan S, Cayenne S etc.
        ['T',                   'T',                    'Porsche',       100], // Macan T entry trim

        // ── Maserati ──────────────────────────────────────────────────────────
        ['그란스포츠',          'GranSport',            'Maserati',      100], // Quattroporte/GranTurismo
        ['그란루쏘',            'GranLusso',            'Maserati',      100], // luxury-oriented variant

        // ── Volvo ─────────────────────────────────────────────────────────────
        ['프로',                'Pro',                  'Volvo',         100], // V60/V90 Cross Country Pro

        // ── Lexus ─────────────────────────────────────────────────────────────
        ['럭셔리 플러스',       'Luxury Plus',          'Lexus',         100], // space-variant of 럭셔리플러스

        // ── Mercedes-Benz ─────────────────────────────────────────────────────
        ['마이바흐',            'Maybach',              'Mercedes-Benz', 100], // S-Class Maybach sub-brand

        // ── Chevrolet ─────────────────────────────────────────────────────────
        ['RS',                  'RS',                   'Chevrolet',     100], // Trax RS trim

        // ── Rolls-Royce ───────────────────────────────────────────────────────
        ['블랙 배지',           'Black Badge',          'Rolls-Royce',   100], // sport-spec variant
        ['EWB',                 'EWB',                  'Rolls-Royce',   100], // Extended Wheelbase
        ['던',                  'Dawn',                 'Rolls-Royce',   100], // Rolls-Royce Dawn

        // ── Land Rover compound trims ─────────────────────────────────────────
        ['다이나믹 SE',         'Dynamic SE',           'Land Rover',    100], // Range Rover Sport
        ['다이나믹 HSE',        'Dynamic HSE',          'Land Rover',    100], // Range Rover Sport

        // ── Kia ───────────────────────────────────────────────────────────────
        ['MX',                  'MX',                   'Kia',           100], // K5 MX trim
        ['SX',                  'SX',                   'Kia',           100], // K5 SX trim
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
        '제네시스|G80'  => ['DH', 'RG', 'RG3'],  // DH=현대 제네시스 세단 → 브랜드 분리 후 G80
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
        'BMW|5시리즈'   => ['E34', 'E39', 'E60', 'F10', 'G30', 'G60'],  // G60 = 8세대 2023+
        'BMW|6시리즈'   => ['E63', 'F12', 'G32'],
        'BMW|7시리즈'   => ['E38', 'E65', 'F01', 'G11', 'G12', 'G70'],  // G12=LWB G11, G70=7세대 2022+
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
        '벤츠|GLE'      => ['W166', 'W167', 'V167'],  // W167=2019+ standard, V167=LWB
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
            CatalogTrim::query()->truncate();
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

        if (!$this->option('skip-trims')) {
            $newTrims = $this->seedCatalogTrims();
            $this->info("New catalog trims:    {$newTrims}");
        }

        return self::SUCCESS;
    }

    private function seedCatalogTrims(): int
    {
        $new = 0;
        foreach (self::CATALOG_TRIMS_SEED as [$trimKr, $trimEn, $makeEn, $priority]) {
            $row = CatalogTrim::firstOrCreate(
                ['trim_kr' => (string) $trimKr, 'make_en' => (string) $makeEn],
                ['trim_en' => ($trimEn !== null && $trimEn !== '') ? $trimEn : null, 'priority' => $priority],
            );
            if ($row->wasRecentlyCreated) {
                $new++;
            }
        }
        return $new;
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
