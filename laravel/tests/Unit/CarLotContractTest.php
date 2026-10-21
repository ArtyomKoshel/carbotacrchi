<?php

namespace Tests\Unit;

use App\Ingest\CarLotContract;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP side of the CarLot v1 contract: the same shared fixtures the
 * collector's pytest suite checks (contracts/fixtures/carlot.v1) must be
 * accepted or refused here too.
 */
final class CarLotContractTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3).'/contracts';
    }

    private static function contract(): CarLotContract
    {
        return new CarLotContract(self::root().'/carlot.v1.schema.json');
    }

    /** @return iterable<string, array{string}> */
    public static function validFixtures(): iterable
    {
        foreach (glob(self::root().'/fixtures/carlot.v1/valid/*.json') ?: [] as $path) {
            yield basename($path, '.json') => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFixtures(): iterable
    {
        foreach (glob(self::root().'/fixtures/carlot.v1/invalid/*.json') ?: [] as $path) {
            yield basename($path, '.json') => [$path];
        }
    }

    public function test_fixture_sets_exist(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::validFixtures()));
        $this->assertNotEmpty(iterator_to_array(self::invalidFixtures()));
    }

    #[DataProvider('validFixtures')]
    public function test_valid_fixture_is_accepted(string $path): void
    {
        $errors = self::contract()->errors((string) file_get_contents($path));
        $this->assertSame([], $errors, implode("\n", $errors));
    }

    #[DataProvider('invalidFixtures')]
    public function test_invalid_fixture_is_refused(string $path): void
    {
        $this->assertNotSame([], self::contract()->errors((string) file_get_contents($path)));
    }

    public function test_array_payload_is_validated_like_json(): void
    {
        $data = json_decode((string) file_get_contents(self::root().'/fixtures/carlot.v1/valid/minimal.json'), true);
        $this->assertTrue(self::contract()->isValid($data));
        $data['vehicle']['vin'] = 'KMH1';
        $errors = self::contract()->errors($data);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/vehicle/vin', implode("\n", $errors));
    }

    public function test_missing_schema_file_is_reported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CarLotContract(self::root().'/does-not-exist.json');
    }
}
