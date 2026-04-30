<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAPPINGS = [
        // ── Hyundai ──────────────────────────────────────────────────────────
        'Ioniq 9'       => ['아이오닉 9', '아이오닉9'],
        'Ioniq 6'       => ['아이오닉 6', '아이오닉6'],
        'Ioniq 5'       => ['아이오닉 5', '아이오닉5'],
        'Ioniq'         => ['아이오닉'],
        'Tucson'        => ['투싼', '투산'],
        'Palisade'      => ['팰리세이드'],
        'Santa Fe'      => ['싼타페', '산타페'],
        'Sonata'        => ['소나타', '쏘나타'],
        'Grandeur'      => ['그랜저'],
        'Avante'        => ['아반떼', '아반테'],
        'Staria'        => ['스타리아'],
        'Casper'        => ['캐스퍼'],
        'Nexo'          => ['넥쏘'],
        'Kona'          => ['코나'],
        'Venue'         => ['베뉴'],
        'Porter'        => ['포터'],
        'Starex'        => ['스타렉스'],
        'MaxCruze'      => ['맥스크루즈'],
        'Accent'        => ['엑센트'],
        'Veloster'      => ['벨로스터'],
        'Equus'         => ['에쿠스'],
        'Dynasty'       => ['다이너스티'],
        'Terracan'      => ['테라칸'],
        'Galloper'      => ['갤로퍼'],
        'Lavita'        => ['라비타'],
        'Atos'          => ['아토스'],
        'Tiburon'       => ['티뷰론'],
        'Verna'         => ['베르나'],
        'Mighty'        => ['마이티'],
        'Solati'        => ['솔라티'],
        // ── Kia ──────────────────────────────────────────────────────────────
        'Sportage'      => ['스포티지'],
        'Sorento'       => ['쏘렌토'],
        'Carnival'      => ['카니발'],
        'Seltos'        => ['셀토스'],
        'Telluride'     => ['텔루라이드'],
        'Mohave'        => ['모하비'],
        'Stinger'       => ['스팅어'],
        'Niro'          => ['니로'],
        'Soul'          => ['쏘울'],
        'Carens'        => ['카렌스'],
        'Bongo'         => ['봉고'],
        'Ray'           => ['레이'],
        'Morning'       => ['모닝'],
        'Pregio'        => ['프레지오'],
        'Retona'        => ['레토나'],
        'Rio'           => ['리오'],
        'K5'            => ['k5', '옵티마'],
        'K8'            => ['k8', '카덴자'],
        'K9'            => ['k9', '퀀텀'],
        'K3'            => ['k3', '세라토', '포르테'],
        // ── Chevrolet / GM Korea ──────────────────────────────────────────────
        'Trailblazer'   => ['트레일블레이저'],
        'Equinox'       => ['이쿼녹스'],
        'Traverse'      => ['트래버스'],
        'Colorado'      => ['콜로라도'],
        'Silverado'     => ['실버라도'],
        'Suburban'      => ['서버번'],
        'Tahoe'         => ['타호'],
        'Malibu'        => ['말리부'],
        'Trax'          => ['트렉스'],
        'Spark'         => ['스파크'],
        'Bolt EUV'      => ['볼트 euv', '볼트euv'],
        'Bolt EV'       => ['볼트 ev', '볼트ev'],
        'Orlando'       => ['올란도'],
        'Captiva'       => ['캡티바'],
        'Cruze'         => ['크루즈'],
        'Matiz'         => ['마티즈'],
        'Lacetti'       => ['라세티'],
        'Impala'        => ['임팔라'],
        // ── Renault Korea ────────────────────────────────────────────────────
        'Grand Koleos'  => ['그랑 콜레오스', '그랑콜레오스'],
        'Koleos'        => ['콜레오스'],
        'Arkana'        => ['아르카나'],
        'Zoe'           => ['조에'],
        'Twizy'         => ['트위지'],
        // ── KG Mobility / SsangYong ──────────────────────────────────────────
        'Torres EVX'    => ['토레스 evx'],
        'Torres'        => ['토레스'],
        'Tivoli Air'    => ['티볼리 에어'],
        'Tivoli'        => ['티볼리'],
        'Korando'       => ['코란도'],
        'Rexton Sports' => ['렉스턴 스포츠'],
        'Rexton'        => ['렉스턴'],
        'Musso'         => ['무쏘'],
        'Actyon'        => ['액티언'],
        'Rodius'        => ['로디우스'],
        'Chairman'      => ['체어맨'],
        'Istana'        => ['이스타나'],
        // ── Genesis ──────────────────────────────────────────────────────────
        'GV80'          => ['gv80', '지브이80'],
        'GV70'          => ['gv70', '지브이70'],
        'GV60'          => ['gv60', '지브이60'],
        'GV50'          => ['gv50', '지브이50'],
        'G90'           => ['g90'],
        'G80'           => ['g80'],
        'G70'           => ['g70'],
        // ── EV (cross-brand) ─────────────────────────────────────────────────
        'EV9'               => ['ev9'],
        'EV6'               => ['ev6'],
        'EV3'               => ['ev3'],
        'EQ900'             => ['eq900'],        // Genesis G90 old nameplate
        // ── BMW ──────────────────────────────────────────────────────────────
        '3 Series GT'       => ['3시리즈 gt'],
        '5 Series GT'       => ['5시리즈 gt'],
        '6 Series GT'       => ['6시리즈 gt'],
        '2 Series Active Tourer' => ['2시리즈 액티브 투어러'],
        '1 Series'          => ['1시리즈'],
        '2 Series'          => ['2시리즈'],
        '3 Series'          => ['3시리즈'],
        '4 Series'          => ['4시리즈'],
        '5 Series'          => ['5시리즈'],
        '6 Series'          => ['6시리즈'],
        '7 Series'          => ['7시리즈'],
        '8 Series'          => ['8시리즈'],
        // ── Mercedes-Benz ────────────────────────────────────────────────────
        'CLS'               => ['cls-클래스'],
        'CLA'               => ['cla-클래스'],
        'GLE'               => ['gle-클래스'],
        'GLC'               => ['glc-클래스'],
        'GLA'               => ['gla-클래스'],
        'GLB'               => ['glb-클래스'],
        'GLS'               => ['gls-클래스'],
        'AMG GT'            => ['amg gt'],
        'SLK'               => ['slk-클래스'],
        'SLC'               => ['slc-클래스'],
        'SL'                => ['sl-클래스'],
        'G-Class'           => ['g-클래스'],
        'A-Class'           => ['a-클래스'],
        'B-Class'           => ['b-클래스'],
        'V-Class'           => ['v-클래스'],
        'E-Class'           => ['e-클래스'],
        'C-Class'           => ['c-클래스'],
        'S-Class'           => ['s-클래스'],
        'Maybach'           => ['마이바흐'],
        // ── Land Rover ───────────────────────────────────────────────────────
        'Range Rover Sport' => ['레인지로버 스포츠'],
        'Range Rover Evoque'=> ['레인지로버 이보크'],
        'Range Rover Velar' => ['레인지로버 벨라'],
        'Range Rover'       => ['레인지로버'],
        'Discovery Sport'   => ['디스커버리 스포츠'],
        'Discovery 4'       => ['디스커버리 4'],
        'Discovery 5'       => ['디스커버리 5'],
        'Discovery'         => ['디스커버리'],
        'Defender'          => ['디펜더'],
        'Freelander'        => ['프리랜더'],
        // ── MINI ─────────────────────────────────────────────────────────────
        'Cooper Clubman'    => ['쿠퍼 클럽맨'],
        'Cooper Countryman' => ['쿠퍼 컨트리맨'],
        'Cooper Convertible'=> ['쿠퍼 컨버터블'],
        'Cooper Roadster'   => ['쿠퍼 로드스터'],
        'Cooper Paceman'    => ['쿠퍼 페이스맨'],
        'Cooper'            => ['쿠퍼'],
        // ── Toyota ───────────────────────────────────────────────────────────
        'Camry'             => ['캠리'],
        'Corolla'           => ['카롤라'],
        'Prius'             => ['프리우스'],
        'Avalon'            => ['아발론'],
        'Highlander'        => ['하이랜더'],
        'RAV4'              => ['라브4'],
        'Land Cruiser'      => ['랜드크루저'],
        'Alphard'           => ['알파드'],
        // ── Lexus ────────────────────────────────────────────────────────────
        'LS460'             => ['ls460'],
        'LS500'             => ['ls500'],
        'NX300'             => ['nx300'],
        'ES300'             => ['es300'],
        'ES350'             => ['es350'],
        'RX350'             => ['rx350'],
        'RX450'             => ['rx450'],
        'IS250'             => ['is250'],
        'IS300'             => ['is300'],
        'GS350'             => ['gs350'],
        'LX570'             => ['lx570'],
        'UX250'             => ['ux250'],
        // ── Honda ────────────────────────────────────────────────────────────
        'Accord'            => ['어코드'],
        'Civic'             => ['시빅'],
        'Odyssey'           => ['오딧세이'],
        'Jazz'              => ['재즈'],
        // ── Nissan ───────────────────────────────────────────────────────────
        'Altima'            => ['알티마'],
        'Murano'            => ['무라노'],
        'Pathfinder'        => ['패스파인더'],
        'X-Trail'           => ['엑스트레일'],
        // ── Audi ─────────────────────────────────────────────────────────────
        'A4'                => ['에이포'],
        'A6'                => ['에이식스'],
        'Q5'                => ['큐오'],
        'Q7'                => ['큐칠'],
        // ── Volkswagen ───────────────────────────────────────────────────────
        'Golf'              => ['골프'],
        'Passat'            => ['파사트'],
        'Tiguan'            => ['티구안'],
        'Touareg'           => ['투아렉'],
        'Polo'              => ['폴로'],
        'Arteon'            => ['아르테온'],
        // ── Renault Korea SM/QM/XM ───────────────────────────────────────────
        'SM3'               => ['sm3'],
        'SM5'               => ['sm5'],
        'SM6'               => ['sm6'],
        'SM7'               => ['sm7'],
        'QM3'               => ['qm3'],
        'QM5'               => ['qm5'],
        'QM6'               => ['qm6'],
        'XM3'               => ['xm3'],
        // ── Lincoln ──────────────────────────────────────────────────────────
        'MKZ'               => ['mkz'],
        'MKX'               => ['mkx'],
        'MKC'               => ['mkc'],
        'Navigator'         => ['내비게이터'],
        // ── Dodge ────────────────────────────────────────────────────────────
        'Challenger'        => ['챌린저'],
        'Charger'           => ['차저'],
        'Durango'           => ['듀랑고'],
        // ── Porsche ──────────────────────────────────────────────────────────
        'Cayenne'           => ['카이엔'],
        'Macan'             => ['마칸'],
        'Panamera'          => ['파나메라'],
        'Taycan'            => ['타이칸'],
        // ── Cadillac ─────────────────────────────────────────────────────────
        'Escalade'          => ['에스컬레이드'],
        // ── Jeep ─────────────────────────────────────────────────────────────
        'Grand Cherokee'    => ['그랜드 체로키'],
        'Cherokee'          => ['체로키'],
        'Wrangler'          => ['랭글러'],
        'Compass'           => ['컴패스'],
        'Renegade'          => ['레니게이드'],
        // ── Kia (additional) ─────────────────────────────────────────────────
        'K7'                => ['k7'],
        'Pride'             => ['프라이드'],
    ];

    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'model_en')) {
                $table->string('model_en', 100)->nullable()->after('model');
                $table->index('model_en');
            }
        });

        foreach (self::MAPPINGS as $english => $koreans) {
            foreach ($koreans as $korean) {
                DB::table('lots')
                    ->whereNull('model_en')
                    ->where('model', 'LIKE', '%' . $korean . '%')
                    ->update(['model_en' => $english]);
            }
            DB::table('lots')
                ->whereNull('model_en')
                ->where('model', 'LIKE', '%' . $english . '%')
                ->update(['model_en' => $english]);
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex(['model_en']);
            $table->dropColumn('model_en');
        });
    }
};
