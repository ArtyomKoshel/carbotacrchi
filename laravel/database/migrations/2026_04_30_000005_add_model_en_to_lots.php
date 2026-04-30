<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAPPINGS = [
        'Tucson'    => ['투싼', '투산'],
        'Sonata'    => ['소나타', '쏘나타'],
        'Avante'    => ['아반떼', '아반테'],
        'Grandeur'  => ['그랜저'],
        'Palisade'  => ['팰리세이드'],
        'Santa Fe'  => ['싼타페', '산타페'],
        'Ioniq'     => ['아이오닉'],
        'Kona'      => ['코나'],
        'Venue'     => ['베뉴'],
        'Staria'    => ['스타리아'],
        'Casper'    => ['캐스퍼'],
        'Nexo'      => ['넥쏘'],
        'Sportage'  => ['스포티지'],
        'Sorento'   => ['쏘렌토'],
        'Carnival'  => ['카니발'],
        'Seltos'    => ['셀토스'],
        'Telluride' => ['텔루라이드'],
        'Mohave'    => ['모하비'],
        'Stinger'   => ['스팅어'],
        'K5'        => ['k5', '옵티마'],
        'K8'        => ['k8', '카덴자'],
        'K3'        => ['k3', '세라토', '포르테'],
        'Morning'   => ['모닝'],
        'Spark'     => ['스파크'],
        'Malibu'    => ['말리부'],
        'Ray'       => ['레이'],
        'G80'       => ['g80'],
        'GV80'      => ['gv80'],
        'G90'       => ['g90'],
        'GV70'      => ['gv70'],
        'G70'       => ['g70'],
        'GV60'      => ['gv60'],
        'GV50'      => ['gv50'],
        'EV6'       => ['ev6'],
        'EV9'       => ['ev9'],
        'EV3'       => ['ev3'],
        'Niro'      => ['니로'],
        'Trax'      => ['트렉스'],
        'Equinox'   => ['이쿼녹스'],
        'Traverse'  => ['트래버스'],
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
