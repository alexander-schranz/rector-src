<?php

declare(strict_types=1);

namespace Rector\DowngradePhp80\Rector\Class_;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PHPStan\Type\MixedType;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTypeChanger;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\NodeManipulator\ClassInsertManipulator;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://wiki.php.net/rfc/constructor_promotion
 *
 * @see \Rector\Tests\DowngradePhp80\Rector\Class_\DowngradePropertyPromotionRector\DowngradePropertyPromotionRectorTest
 */
final class DowngradePropertyPromotionRector extends AbstractRector
{
    public function __construct(
        private ClassInsertManipulator $classInsertManipulator,
        private PhpDocTypeChanger $phpDocTypeChanger
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Change constructor property promotion to property asssign', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function __construct(public float $value = 0.0)
    {
    }
}
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
    public float $value;

    public function __construct(float $value = 0.0)
    {
        $this->value = $value;
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $oldComments = $this->getOldComments($node);
        $promotedParams = $this->resolvePromotedParams($node);
        if ($promotedParams === []) {
            return null;
        }

        $properties = $this->resolvePropertiesFromPromotedParams($promotedParams, $node);

        $this->addPropertyAssignsToConstructorClassMethod($properties, $node, $oldComments);

        foreach ($promotedParams as $promotedParam) {
            $promotedParam->flags = 0;
        }

        return $node;
    }

    /**
     * @return array<string, Comment|null>
     */
    private function getOldComments(Class_ $class): array
    {
        $constructorClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (! $constructorClassMethod instanceof ClassMethod) {
            return [];
        }

        $oldComments = [];
        foreach ($constructorClassMethod->params as $param) {
            $oldComments[$this->getName($param->var)] = $param->getAttribute(AttributeKey::COMMENTS);
        }

        return $oldComments;
    }

    /**
     * @return Param[]
     */
    private function resolvePromotedParams(Class_ $class): array
    {
        $constructorClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (! $constructorClassMethod instanceof ClassMethod) {
            return [];
        }

        $promotedParams = [];

        foreach ($constructorClassMethod->params as $param) {
            if ($param->flags === 0) {
                continue;
            }

            $this->setParamAttrGroupAsComment($param);
            $promotedParams[] = $param;
        }

        return $promotedParams;
    }

    private function setParamAttrGroupAsComment(Param $param): void
    {
        $attrGroupsPrint = $this->betterStandardPrinter->print($param->attrGroups);

        $comments = $param->getAttribute(AttributeKey::COMMENTS);
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                $attrGroupsPrint = str_replace($comment->getText(), '', $attrGroupsPrint);
            }
        }

        $comments = $param->attrGroups !== []
            ? [new Comment($attrGroupsPrint)]
            : null;

        $param->attrGroups = [];
        $param->setAttribute(AttributeKey::COMMENTS, $comments);
    }

    /**
     * @param Param[] $promotedParams
     * @return Property[]
     */
    private function resolvePropertiesFromPromotedParams(array $promotedParams, Class_ $class): array
    {
        $properties = $this->createPropertiesFromParams($promotedParams);
        $this->classInsertManipulator->addPropertiesToClass($class, $properties);

        return $properties;
    }

    /**
     * @param Property[] $properties
     * @param array<string, Comment|null> $oldComments
     */
    private function addPropertyAssignsToConstructorClassMethod(
        array $properties,
        Class_ $class,
        array $oldComments
    ): void {
        $assigns = [];

        foreach ($properties as $property) {
            $propertyName = $this->getName($property);
            $assign = $this->nodeFactory->createPropertyAssignment($propertyName);
            $expression = new Expression($assign);
            $expression->setAttribute(AttributeKey::COMMENTS, $oldComments[$propertyName]);
            $assigns[] = $expression;
        }

        /** @var ClassMethod $constructorClassMethod */
        $constructorClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        $constructorClassMethod->stmts = array_merge($assigns, (array) $constructorClassMethod->stmts);
    }

    /**
     * @param Param[] $params
     * @return Property[]
     */
    private function createPropertiesFromParams(array $params): array
    {
        $properties = [];

        foreach ($params as $param) {
            /** @var string $name */
            $name = $this->getName($param->var);

            $property = $this->nodeFactory->createProperty($name);
            $property->flags = $param->flags;
            $property->type = $param->type;
            $this->decoratePropertyWithParamDocInfo($param, $property);

            if ($param->default !== null) {
                $property->props[0]->default = $param->default;
            }

            $properties[] = $property;
        }

        return $properties;
    }

    private function decoratePropertyWithParamDocInfo(Param $param, Property $property): void
    {
        $constructorClassMethod = $this->betterNodeFinder->findParentType($param, ClassMethod::class);
        if (! $constructorClassMethod instanceof ClassMethod) {
            throw new ShouldNotHappenException();
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($constructorClassMethod);
        if (! $phpDocInfo instanceof PhpDocInfo) {
            return;
        }

        $name = $this->getName($param->var);
        if ($name === null) {
            return;
        }

        $type = $phpDocInfo->getParamType($name);

        // MixedType likely means there was no param type defined
        if ($type instanceof MixedType) {
            return;
        }

        $propertyDocInfo = $this->phpDocInfoFactory->createEmpty($property);
        $this->phpDocTypeChanger->changeVarType($propertyDocInfo, $type);
    }
}
