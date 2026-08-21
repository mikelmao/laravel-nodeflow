<?php

use Nodeflow\Console\SourceText;

it('strips line and block comments', function () {
    $source = <<<'TS'
    // resolve: { alias: { '@nodeflow/editor': 'x' } }
    const a = 1
    /* resolve: { dedupe: ['react'] } */
    const b = 2
    TS;

    $stripped = SourceText::withoutJsComments($source);

    expect($stripped)->toContain('const a = 1')
        ->toContain('const b = 2')
        ->not->toContain('@nodeflow/editor')
        ->not->toContain('dedupe');
});

it('preserves a double slash inside a string', function () {
    // Counterfactual: strip with a regex on // and this fails, truncating every
    // config line that mentions a URL and reporting a wired host as unwired.
    expect(SourceText::withoutJsComments("const u = 'https://example.test/a'"))
        ->toContain("'https://example.test/a'");
});

it('preserves a comment opener inside a template literal', function () {
    expect(SourceText::withoutJsComments('const t = `a /* b */ c`'))
        ->toContain('`a /* b */ c`');
});

it('preserves an escaped quote without ending the string early', function () {
    // Counterfactual: drop the backslash handling and the scanner treats the rest
    // of the file as string content, so every real check silently passes.
    $source = "const s = 'it\\'s here' // gone\nconst t = 2";

    $stripped = SourceText::withoutJsComments($source);

    expect($stripped)->toContain("'it\\'s here'")
        ->toContain('const t = 2')
        ->not->toContain('gone');
});

it('strips css block comments and leaves quoted urls alone', function () {
    $css = "/* @source 'x'; */\n@source '../../vendor/a/b/resources/js';\n";

    $stripped = SourceText::withoutCssComments($css);

    expect($stripped)->toContain("@source '../../vendor/a/b/resources/js';")
        ->not->toContain("@source 'x'");
});
