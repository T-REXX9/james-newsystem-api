<?php

declare(strict_types=1);

/**
 * Pure unit test for the province-resolution logic in
 * CustomerDatabaseRepository::resolveGeoJsonProvince().
 *
 * This guards the Sales Map's map↔sidebar consistency:
 *   - the map's colour counts come from /customer-database/province-summary
 *   - the sidebar's customer list comes from /customer-database
 *   - both must agree on the same GeoJSON-canonical province name,
 *     so the same alias map is applied on both sides.
 *
 * Run:
 *   php api/tests/CustomerProvinceResolverUnitTest.php
 */

require __DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php';

use App\Repositories\CustomerDatabaseRepository;

$passed = 0;
$failed = 0;
$failures = [];

$expect = static function (string $name, ?string $expected, ?string $actual) use (&$passed, &$failed, &$failures): void {
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$name}\n";
        return;
    }
    $failed++;
    $exp = $expected === null ? 'null' : $expected;
    $act = $actual === null ? 'null' : $actual;
    $msg = "{$name}: expected={$exp} actual={$act}";
    $failures[] = $msg;
    echo "  FAIL {$msg}\n";
};

echo "==========================================================\n";
echo " Province Resolver Unit Test\n";
echo "==========================================================\n\n";

// ----- Aliases that MUST be present (smoke test) -----

$expect('canonical Quezon name passes through',           'Quezon',          CustomerDatabaseRepository::resolveGeoJsonProvince('Quezon'));
$expect('canonical name with extra whitespace is trimmed', 'Quezon',         CustomerDatabaseRepository::resolveGeoJsonProvince('  quezon  '));
$expect('canonical name is case-insensitive',             'Quezon',          CustomerDatabaseRepository::resolveGeoJsonProvince('QUEZON'));

// ----- Aliases that must collapse into the same canonical name -----

$expect('"City of Isabela" alias maps to Isabela',         'Isabela',         CustomerDatabaseRepository::resolveGeoJsonProvince('City of Isabela'));
$expect('Davao de Oro alias maps to Compostela Valley',    'Compostela Valley', CustomerDatabaseRepository::resolveGeoJsonProvince('Davao de Oro'));
$expect('Davao Occidental alias maps to Davao del Sur',    'Davao del Sur',   CustomerDatabaseRepository::resolveGeoJsonProvince('Davao Occidental'));
$expect('Cotabato City alias maps to Maguindanao',         'Maguindanao',     CustomerDatabaseRepository::resolveGeoJsonProvince('Cotabato City'));
$expect('Cotabato (North Cotabato) maps to North Cotabato','North Cotabato',  CustomerDatabaseRepository::resolveGeoJsonProvince('Cotabato (North Cotabato)'));
$expect('Samar (Western Samar) maps to Samar',             'Samar',           CustomerDatabaseRepository::resolveGeoJsonProvince('Samar (Western Samar)'));

// ----- NCR districts collapse to Metropolitan Manila -----

$expect('NCR, City of Manila, First District -> Metro Manila', 'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('NCR, City of Manila, First District'));
$expect('NCR, Second District -> Metro Manila',                'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('NCR, Second District'));
$expect('NCR, Third District -> Metro Manila',                 'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('NCR, Third District'));
$expect('NCR, Fourth District -> Metro Manila',                'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('NCR, Fourth District'));
$expect('City of Manila -> Metro Manila',                      'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('City of Manila'));
$expect('Manila -> Metro Manila',                              'Metropolitan Manila', CustomerDatabaseRepository::resolveGeoJsonProvince('Manila'));

// ----- Negative cases -----

$expect('empty string returns null',                          null,             CustomerDatabaseRepository::resolveGeoJsonProvince(''));
$expect('whitespace-only returns null',                       null,             CustomerDatabaseRepository::resolveGeoJsonProvince('   '));
$expect('unrecognised province returns null (no false match)', null,             CustomerDatabaseRepository::resolveGeoJsonProvince('Atlantis'));

// ----- Spot-check: every value in the alias map is non-empty -----

$reflection = new ReflectionClass(CustomerDatabaseRepository::class);
$map = $reflection->getReflectionConstant('GEOJSON_PROVINCE_MAP')->getValue();
$mappedCount = 0;
foreach ($map as $dbName => $geoName) {
    $expect("alias '{$dbName}' -> '{$geoName}' is non-empty geo name", $geoName, CustomerDatabaseRepository::resolveGeoJsonProvince($dbName));
    if ($geoName !== '' && $geoName !== null) {
        $mappedCount++;
    }
}

// ----- Sanity: all canonical GeoJSON names must round-trip -----

$canonicalSeen = [];
foreach ($map as $dbName => $geoName) {
    $canonicalSeen[$geoName] = true;
}
foreach (array_keys($canonicalSeen) as $geoName) {
    $expect("canonical '{$geoName}' round-trips to itself", $geoName, CustomerDatabaseRepository::resolveGeoJsonProvince($geoName));
}

echo "\n==========================================================\n";
echo " Passed: {$passed}\n";
echo " Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

echo "\nAll province resolver assertions passed.\n";
exit(0);
