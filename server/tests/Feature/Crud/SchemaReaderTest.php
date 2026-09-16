<?php

use App\Support\Crud\Column;
use App\Support\Crud\FieldMapper;
use App\Support\Crud\SchemaReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('读取列定义：顺序/类型/可空/默认/注释/主键/审计列标记', function () {
    Schema::dropIfExists('fx_items');
    Schema::create('fx_items', function (Blueprint $t) {
        $t->id();
        $t->string('title', 100)->comment('标题');
        $t->text('content')->nullable()->comment('正文');
        $t->integer('views')->default(0)->comment('浏览数');
        $t->boolean('is_top')->default(false);
        $t->decimal('price', 10, 2)->nullable();
        $t->timestamp('published_at')->nullable()->comment('发布时间');
        $t->jsonb('meta')->nullable();
        $t->timestamps();
    });

    $cols = (new SchemaReader)->columns('fx_items');

    expect(array_column($cols, 'name'))->toBe([
        'id', 'title', 'content', 'views', 'is_top', 'price', 'published_at', 'meta', 'created_at', 'updated_at',
    ]);

    $byName = collect($cols)->keyBy(fn ($c) => $c->name);

    expect($byName['id']->primaryKey)->toBeTrue()
        ->and($byName['id']->skipForm())->toBeTrue()
        ->and($byName['title']->udtName)->toBe('varchar')
        ->and($byName['title']->maxLength)->toBe(100)
        ->and($byName['title']->comment)->toBe('标题')
        ->and($byName['title']->nullable)->toBeFalse()
        ->and($byName['title']->hasDefault)->toBeFalse()
        ->and($byName['title']->skipForm())->toBeFalse()
        ->and($byName['content']->udtName)->toBe('text')
        ->and($byName['content']->nullable)->toBeTrue()
        ->and($byName['views']->udtName)->toBe('int4')
        ->and($byName['views']->hasDefault)->toBeTrue()
        ->and($byName['is_top']->udtName)->toBe('bool')
        ->and($byName['price']->udtName)->toBe('numeric')
        ->and($byName['published_at']->udtName)->toBe('timestamp')
        ->and($byName['published_at']->comment)->toBe('发布时间')
        ->and($byName['meta']->udtName)->toBe('jsonb')
        ->and($byName['created_at']->skipForm())->toBeTrue()
        ->and($byName['updated_at']->skipForm())->toBeTrue();
});

it('表不存在时抛出可读异常', function () {
    expect(fn () => (new SchemaReader)->columns('fx_missing'))
        ->toThrow(RuntimeException::class, '数据表 [fx_missing] 不存在');
});

it('类型映射覆盖常用集并拒绝未知类型', function () {
    $mk = fn (string $udt, bool $nullable = true, ?int $max = null) => new Column(
        name: 'col', udtName: $udt, nullable: $nullable, hasDefault: false, maxLength: $max, comment: null, primaryKey: false,
    );

    // varchar：可搜索的短文本 + 长度校验
    $varchar = FieldMapper::map($mk('varchar', false, 100));
    expect($varchar['phpType'])->toBe('string')
        ->and($varchar['component'])->toBe('el-input')
        ->and($varchar['searchable'])->toBeTrue()
        ->and($varchar['rules'])->toBe(['required', 'string', 'max:100']);

    // text：长文本，textarea，不进列表、不参与搜索
    $text = FieldMapper::map($mk('text'));
    expect($text['component'])->toBe('el-input')
        ->and($text['props'])->toContain('textarea')
        ->and($text['longText'])->toBeTrue()
        ->and($text['searchable'])->toBeFalse()
        ->and($text['rules'])->toBe(['nullable', 'string']);

    // 整型 / 布尔 / 时间 / 数值 / jsonb
    expect(FieldMapper::map($mk('int4', false))['rules'])->toBe(['required', 'integer'])
        ->and(FieldMapper::map($mk('int4', false))['cast'])->toBe('integer')
        ->and(FieldMapper::map($mk('bool', false))['component'])->toBe('el-switch')
        ->and(FieldMapper::map($mk('bool', false))['cast'])->toBe('boolean')
        ->and(FieldMapper::map($mk('timestamp'))['cast'])->toBe('datetime')
        ->and(FieldMapper::map($mk('timestamp'))['component'])->toBe('el-date-picker')
        ->and(FieldMapper::map($mk('date'))['props'])->toContain('type="date"')
        ->and(FieldMapper::map($mk('numeric'))['component'])->toBe('el-input-number')
        ->and(FieldMapper::map($mk('jsonb'))['longText'])->toBeTrue();

    // 可空列不产生 required；timestamp(date/timestamp) 的 date picker 用 datetime 形态
    expect(FieldMapper::map($mk('int4'))['rules'])->toBe(['nullable', 'integer']);

    expect(fn () => FieldMapper::map($mk('inet')))
        ->toThrow(RuntimeException::class, 'inet');
});
