<?php

declare(strict_types=1);

namespace Rector\Transform\ValueObject;

use PHPStan\Type\ObjectType;
use Rector\Core\Validation\RectorAssert;

final class UnsetAndIssetToMethodCall
{
    public function __construct(
        private string $type,
        private string $issetMethodCall,
        private string $unsedMethodCall
    ) {
        RectorAssert::className($type);
    }

    public function getObjectType(): ObjectType
    {
        return new ObjectType($this->type);
    }

    public function getIssetMethodCall(): string
    {
        return $this->issetMethodCall;
    }

    public function getUnsedMethodCall(): string
    {
        return $this->unsedMethodCall;
    }
}
