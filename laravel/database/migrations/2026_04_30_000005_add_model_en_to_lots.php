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
        'EV9'           => ['ev9'],
        'EV6'           => ['ev6'],
        'EV3'           => ['ev3'],
        // ── Toyota ───────────────────────────────────────────────────────────
        'Camry'         => ['캠리'],
        'Corolla'       => ['카롤라'],
        'Prius'         => ['프리우스'],
        'Avalon'        => ['아발론'],
        'Highlander'    => ['하이랜더'],
        'RAV4'          => ['라브4'],
        'Land Cruiser'  => ['랜드크루저'],
        'Alphard'       => ['알파드'],
        // ── Honda ────────────────────────────────────────────────────────────
        'Accord'        => ['어코드'],
        'Civic'         => ['시빅'],
        'Odyssey'       => ['오딧세이'],
        // ── Volkswagen ───────────────────────────────────────────────────────
        'Golf'          => ['골프'],
        'Passat'        => ['파사트'],
        'Tiguan'        => ['티구안'],
        'Touareg'       => ['투아렉'],
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
