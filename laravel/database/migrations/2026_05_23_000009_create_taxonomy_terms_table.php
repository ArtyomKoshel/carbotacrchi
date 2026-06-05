<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('encar')->index();
            $table->enum('term_type', ['trim_hint', 'package_hint', 'tail_powertrain_token'])->index();
            $table->string('term', 191);
            $table->integer('priority')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['source', 'term_type', 'term'], 'taxonomy_terms_unique');
        });

        $seed = [];

        foreach ([
            '가솔린', '디젤', '하이브리드', 'HEV', 'LPG', '전기', 'EV',
            '2WD', '4WD', 'AWD', 'FWD', 'RWD', 'xDrive', 'sDrive',
            '터보', 'TCe', 'TFSI', 'TDI', 'e-VGT', '(택시형)', '(렌터카)', '(영업용)',
        ] as $term) {
            $seed[] = ['source' => 'encar', 'term_type' => 'tail_powertrain_token', 'term' => $term, 'priority' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach ([
            'M 스포츠 플러스', 'M 퍼포먼스', 'M 스포츠',
            'AMG Line', 'GT Line', 'N Line', 'S line', 'xLine',
        ] as $term) {
            $seed[] = ['source' => 'encar', 'term_type' => 'package_hint', 'term' => $term, 'priority' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach ([
            '캘리그래피 블랙에디션', '마스터즈 그래비티', '익스클루시브 스페셜',
            '프레스티지 스페셜', '노블레스 스페셜', '프리미엄 초이스',
            '캘리그래피', '인스퍼레이션', '익스클루시브', '프레스티지', '시그니처',
            '노블레스', '프리미엄', '모던', '스마트', '럭셔리',
            '르블랑', '고급형', '기본형', '비즈니스 2', '비즈니스 1', '모빌리티',
        ] as $term) {
            $seed[] = ['source' => 'encar', 'term_type' => 'trim_hint', 'term' => $term, 'priority' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }

        if (!empty($seed)) {
            DB::table('taxonomy_terms')->insert($seed);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
