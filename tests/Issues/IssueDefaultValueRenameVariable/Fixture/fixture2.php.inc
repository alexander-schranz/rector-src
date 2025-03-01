<?php

declare(strict_types=1);

namespace Rector\Core\Tests\Issues\IssueDefaultValueRenameParam\Fixture;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\Node as DocNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use Rector\BetterPhpDocParser\AnnotationAnalyzer\DoctrineAnnotationTagValueNodeAnalyzer;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;

final class Fixture2
{
    public function check(DoctrineAnnotationTagValueNodeAnalyzer $analyzer, PhpDocNode $node)
    {
        $doctrineAnnotationTagValueNode = $analyzer->resolveDoctrineAnnotationTagValueNode($node);

        if (! $doctrineAnnotationTagValueNode instanceof DoctrineAnnotationTagValueNode) {
            return null;
        }
    }
}

?>
-----
<?php

declare(strict_types=1);

namespace Rector\Core\Tests\Issues\IssueDefaultValueRenameParam\Fixture;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\Node as DocNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use Rector\BetterPhpDocParser\AnnotationAnalyzer\DoctrineAnnotationTagValueNodeAnalyzer;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;

final class Fixture2
{
    public function check(DoctrineAnnotationTagValueNodeAnalyzer $analyzer, PhpDocNode $node)
    {
        $docNode = $analyzer->resolveDoctrineAnnotationTagValueNode($node);

        if (! $docNode instanceof DoctrineAnnotationTagValueNode) {
            return null;
        }
    }
}

?>
