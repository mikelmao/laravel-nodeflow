<?php

use Nodeflow\Contracts\SubjectResolver;

it('throws when calling resolve on the default SubjectResolver binding', function () {
    $resolver = app(SubjectResolver::class);

    expect(fn () => $resolver->resolve('User', ['1', '2']))
        ->toThrow(RuntimeException::class, 'The host application must bind Nodeflow\Contracts\SubjectResolver to resolve subjects for the workflow.');
});
