<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver\PHPStan\Scope;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PHPStan\AnalysedCodeException;
use PHPStan\Analyser\MutatingScope;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeContext;
use PHPStan\BetterReflection\Reflection\Exception\NotAnInterfaceReflection;
use PHPStan\BetterReflection\Reflector\ClassReflector;
use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\Node\UnreachableStatementNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\Caching\Detector\ChangedFilesDetector;
use Rector\Caching\FileSystem\DependencyResolver;
use Rector\Core\Application\FileProcessor;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\PhpParser\Printer\BetterStandardPrinter;
use Rector\Core\StaticReflection\SourceLocator\ParentAttributeSourceLocator;
use Rector\Core\StaticReflection\SourceLocator\RenamedClassesSourceLocator;
use Rector\Core\Stubs\DummyTraitClass;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Scope\NodeVisitor\RemoveDeepChainMethodCallNodeVisitor;
use ReflectionClass;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;
use Symplify\SmartFileSystem\SmartFileInfo;
use Symplify\SmartFileSystem\SmartFileSystem;
use Throwable;

/**
 * @inspired by https://github.com/silverstripe/silverstripe-upgrader/blob/532182b23e854d02e0b27e68ebc394f436de0682/src/UpgradeRule/PHP/Visitor/PHPStanScopeVisitor.php
 * - https://github.com/silverstripe/silverstripe-upgrader/pull/57/commits/e5c7cfa166ad940d9d4ff69537d9f7608e992359#diff-5e0807bb3dc03d6a8d8b6ad049abd774
 */
final class PHPStanNodeScopeResolver
{
    /**
     * @var string
     * @see https://regex101.com/r/aXsCkK/1
     */
    private const ANONYMOUS_CLASS_START_REGEX = '#^AnonymousClass(\w+)#';

    /**
     * @var string
     * @see https://regex101.com/r/AIA24M/1
     */
    private const NOT_AN_INTERFACE_EXCEPTION_REGEX = '#^Provided node ".*" is not interface, but "class"$#';

    public function __construct(
        private ChangedFilesDetector $changedFilesDetector,
        private DependencyResolver $dependencyResolver,
        private NodeScopeResolver $nodeScopeResolver,
        private ReflectionProvider $reflectionProvider,
        private RemoveDeepChainMethodCallNodeVisitor $removeDeepChainMethodCallNodeVisitor,
        private ScopeFactory $scopeFactory,
        private PrivatesAccessor $privatesAccessor,
        private RenamedClassesSourceLocator $renamedClassesSourceLocator,
        private ParentAttributeSourceLocator $parentAttributeSourceLocator,
        private BetterNodeFinder $betterNodeFinder,
        /**
         * use \PhpParser\Parser on purpose instead of extended \Rector\Core\PhpParser\Parser\Parser
         * as detecting `@template-extends` too early on dependent files
         */
        private Parser $parser,
        private BetterStandardPrinter $betterStandardPrinter,
        private SmartFileSystem $smartFileSystem
    ) {
    }

    /**
     * @param Stmt[] $nodes
     * @return Stmt[]
     */
    public function processNodes(array $nodes, SmartFileInfo $smartFileInfo): array
    {
        $this->removeDeepChainMethodCallNodes($nodes);

        $scope = $this->scopeFactory->createFromFile($smartFileInfo);

        // skip chain method calls, performance issue: https://github.com/phpstan/phpstan/issues/254
        $nodeCallback = function (Node $node, MutatingScope $scope) use (&$nodeCallback): void {
            if ($node instanceof Trait_) {
                $traitName = $this->resolveClassName($node);

                $traitReflectionClass = $this->reflectionProvider->getClass($traitName);

                $scopeContext = $this->createDummyClassScopeContext($scope);
                $traitScope = clone $scope;
                $this->privatesAccessor->setPrivateProperty($traitScope, 'context', $scopeContext);

                $traitScope = $traitScope->enterTrait($traitReflectionClass);

                $this->nodeScopeResolver->processStmtNodes($node, $node->stmts, $traitScope, $nodeCallback);
                return;
            }

            // the class reflection is resolved AFTER entering to class node
            // so we need to get it from the first after this one
            if ($node instanceof Class_ || $node instanceof Interface_) {
                /** @var MutatingScope $scope */
                $scope = $this->resolveClassOrInterfaceScope($node, $scope);
            }

            // special case for unreachable nodes
            if ($node instanceof UnreachableStatementNode) {
                $originalNode = $node->getOriginalStatement();
                $originalNode->setAttribute(AttributeKey::IS_UNREACHABLE, true);
                $originalNode->setAttribute(AttributeKey::SCOPE, $scope);
            } else {
                $node->setAttribute(AttributeKey::SCOPE, $scope);
            }
        };

        $this->decoratePHPStanNodeScopeResolverWithRenamedClassSourceLocator($this->nodeScopeResolver);

        // it needs to be checked early before `@mixin` check as
        // ReflectionProvider already hang when check class with `@template-extends`
        if ($this->isTemplateExtendsInSource($nodes, $smartFileInfo->getFilename())) {
            return $nodes;
        }

        return $this->processNodesWithMixinHandling($smartFileInfo, $nodes, $scope, $nodeCallback);
    }

    /**
     * @param Node[] $nodes
     */
    private function isTemplateExtendsInSource(array $nodes, string $currentFileName): bool
    {
        return (bool) $this->betterNodeFinder->findFirst($nodes, function (Node $node) use ($currentFileName): bool {
            if (! $node instanceof FullyQualified) {
                return false;
            }

            $className = $node->toString();

            // fix error in parallel test
            // use function_exists on purpose as using reflectionProvider broke the test in parallel
            if (function_exists($className)) {
                return false;
            }

            // use class_exists as PHPStan ReflectionProvider hang on check className with `@template-extends`
            if (! class_exists($className)) {
                return false;
            }

            // use native ReflectionClass as PHPStan ReflectionProvider hang on check className with `@template-extends`
            $reflectionClass = new ReflectionClass($className);
            if ($reflectionClass->isInternal()) {
                return false;
            }

            $fileName = (string) $reflectionClass->getFileName();
            if (! $this->smartFileSystem->exists($fileName)) {
                return false;
            }

            // already checked in FileProcessor::parseFileInfoToLocalCache()
            if ($fileName === $currentFileName) {
                return false;
            }

            $content = $this->smartFileSystem->readFile($fileName);
            $fileNodes = $this->parser->parse($content);

            $print = $this->betterStandardPrinter->print($fileNodes);
            return (bool) Strings::match($print, FileProcessor::TEMPLATE_EXTENDS_REGEX);
        });
    }

    /**
     * @param Stmt[] $nodes
     * @return Stmt[]
     */
    private function processNodesWithMixinHandling(
        SmartFileInfo $smartFileInfo,
        array $nodes,
        MutatingScope $mutatingScope,
        callable $nodeCallback
    ): array {
        if ($this->isMixinInSource($nodes)) {
            return $nodes;
        }

        try {
            $this->nodeScopeResolver->processNodes($nodes, $mutatingScope, $nodeCallback);
        } catch (Throwable $throwable) {
            if (! $throwable instanceof NotAnInterfaceReflection) {
                throw $throwable;
            }

            if (! Strings::match($throwable->getMessage(), self::NOT_AN_INTERFACE_EXCEPTION_REGEX)) {
                throw $throwable;
            }
        }

        $this->resolveAndSaveDependentFiles($nodes, $mutatingScope, $smartFileInfo);

        return $nodes;
    }

    /**
     * @param Node[] $nodes
     */
    private function isMixinInSource(array $nodes): bool
    {
        return (bool) $this->betterNodeFinder->findFirst($nodes, function (Node $node): bool {
            if (! $node instanceof FullyQualified && ! $node instanceof Class_) {
                return false;
            }

            if ($node instanceof Class_ && $node->isAnonymous()) {
                return false;
            }

            $className = $node instanceof FullyQualified ? $node->toString() : $node->namespacedName->toString();

            return $this->isCircularMixin($className);
        });
    }

    private function isCircularMixin(string $className): bool
    {
        // fix error in parallel test
        // use function_exists on purpose as using reflectionProvider broke the test in parallel
        if (function_exists($className)) {
            return false;
        }

        $hasClass = $this->reflectionProvider->hasClass($className);

        if (! $hasClass) {
            return false;
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        if ($classReflection->isBuiltIn()) {
            return false;
        }

        foreach ($classReflection->getMixinTags() as $mixinTag) {
            $type = $mixinTag->getType();
            if (! $type instanceof ObjectType) {
                return false;
            }

            if ($type->getClassName() === $className) {
                return true;
            }

            if ($this->isCircularMixin($type->getClassName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Node[] $nodes
     */
    private function removeDeepChainMethodCallNodes(array $nodes): void
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($this->removeDeepChainMethodCallNodeVisitor);
        $nodeTraverser->traverse($nodes);
    }

    private function resolveClassOrInterfaceScope(
        Class_ | Interface_ $classLike,
        MutatingScope $mutatingScope
    ): MutatingScope {
        $className = $this->resolveClassName($classLike);

        // is anonymous class? - not possible to enter it since PHPStan 0.12.33, see https://github.com/phpstan/phpstan-src/commit/e87fb0ec26f9c8552bbeef26a868b1e5d8185e91
        if ($classLike instanceof Class_ && Strings::match($className, self::ANONYMOUS_CLASS_START_REGEX)) {
            $classReflection = $this->reflectionProvider->getAnonymousClassReflection($classLike, $mutatingScope);
        } elseif (! $this->reflectionProvider->hasClass($className)) {
            return $mutatingScope;
        } else {
            $classReflection = $this->reflectionProvider->getClass($className);
        }

        return $mutatingScope->enterClass($classReflection);
    }

    private function resolveClassName(Class_ | Interface_ | Trait_ $classLike): string
    {
        if (property_exists($classLike, 'namespacedName')) {
            return (string) $classLike->namespacedName;
        }

        if ($classLike->name === null) {
            throw new ShouldNotHappenException();
        }

        return $classLike->name->toString();
    }

    /**
     * @param Stmt[] $stmts
     */
    private function resolveAndSaveDependentFiles(
        array $stmts,
        MutatingScope $mutatingScope,
        SmartFileInfo $smartFileInfo
    ): void {
        $dependentFiles = [];
        foreach ($stmts as $stmt) {
            try {
                $nodeDependentFiles = $this->dependencyResolver->resolveDependencies($stmt, $mutatingScope);
                $dependentFiles = array_merge($dependentFiles, $nodeDependentFiles);
            } catch (AnalysedCodeException) {
                // @ignoreException
            }
        }

        $this->changedFilesDetector->addFileWithDependencies($smartFileInfo, $dependentFiles);
    }

    /**
     * In case PHPStan tried to parse a file with missing class, it fails.
     * But sometimes we want to rename old class that is missing with Rector..
     *
     * That's why we have to skip fatal errors of PHPStan caused by missing class,
     * so Rector can fix it first. Then run Rector again to refactor code with new classes.
     */
    private function decoratePHPStanNodeScopeResolverWithRenamedClassSourceLocator(
        NodeScopeResolver $nodeScopeResolver
    ): void {
        // 1. get PHPStan locator
        /** @var ClassReflector $classReflector */
        $classReflector = $this->privatesAccessor->getPrivateProperty($nodeScopeResolver, 'classReflector');

        /** @var SourceLocator $sourceLocator */
        $sourceLocator = $this->privatesAccessor->getPrivateProperty($classReflector, 'sourceLocator');

        // 2. get Rector locator
        $aggregateSourceLocator = new AggregateSourceLocator([
            $sourceLocator,
            $this->renamedClassesSourceLocator,
            $this->parentAttributeSourceLocator,
        ]);
        $this->privatesAccessor->setPrivateProperty($classReflector, 'sourceLocator', $aggregateSourceLocator);
    }

    private function createDummyClassScopeContext(MutatingScope $mutatingScope): ScopeContext
    {
        // this has to be faked, because trait PHPStan does not traverse trait without a class
        /** @var ScopeContext $scopeContext */
        $scopeContext = $this->privatesAccessor->getPrivateProperty($mutatingScope, 'context');
        $dummyClassReflection = $this->reflectionProvider->getClass(DummyTraitClass::class);

        // faking a class reflection
        return ScopeContext::create($scopeContext->getFile())
            ->enterClass($dummyClassReflection);
    }
}
