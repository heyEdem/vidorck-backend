<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('keeps documented paths and registered routes in sync', function () {
    $document = Yaml::parseFile(base_path('docs/openapi.yaml'));
    $documented = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'])) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
    }

    $registered = [];
    foreach (Route::getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $registered[] = $method.' /'.$route->uri();
        }
    }

    sort($documented);
    sort($registered);
    expect($registered)->toBe($documented);
});
