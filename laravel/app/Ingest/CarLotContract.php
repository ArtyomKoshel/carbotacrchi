<?php

namespace App\Ingest;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates incoming collector records against the CarLot v1 JSON Schema
 * (docs/adr/0002). The schema file is the single source of truth shared with
 * the Python collector; PHP never re-declares its rules.
 */
final class CarLotContract
{
    public const VERSION = 'carlot/1';

    private readonly Validator $validator;

    private readonly string $schemaId;

    public function __construct(string $schemaPath)
    {
        if (! is_file($schemaPath)) {
            throw new InvalidArgumentException("CarLot schema not found: {$schemaPath}");
        }
        $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
        $this->schemaId = (string) ($schema->{'$id'} ?? throw new InvalidArgumentException('Schema has no $id'));
        $this->validator = new Validator;
        $this->validator->setMaxErrors(20);
        $this->validator->resolver()->registerRaw($schema, $this->schemaId);
    }

    public static function fromConfig(): self
    {
        return new self((string) config('ingest.carlot_schema'));
    }

    /**
     * @param  string|array<mixed>|object  $payload  JSON text or decoded data
     * @return list<string> human-readable errors, empty when valid
     */
    public function errors(string|array|object $payload): array
    {
        $data = is_string($payload)
            ? json_decode($payload, false, 512, JSON_THROW_ON_ERROR)
            : json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($data, $this->schemaId);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ($pointer === '' ? '/' : $pointer).': '.$message;
            }
        }

        return $errors;
    }

    /** @param string|array<mixed>|object $payload */
    public function isValid(string|array|object $payload): bool
    {
        return $this->errors($payload) === [];
    }
}
