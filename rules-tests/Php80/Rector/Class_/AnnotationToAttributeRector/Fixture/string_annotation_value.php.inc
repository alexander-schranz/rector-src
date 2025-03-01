<?php

namespace Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Fixture;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name=App\Entity\Aktenzeichen::TABLE_NAME, uniqueConstraints={@ORM\UniqueConstraint(name="aktenzeichen_idx", columns={"aktenzeichen"})})
 * @ORM\Entity
 */
class StringAnnotationValue
{
}

?>
-----
<?php

namespace Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[\Doctrine\ORM\Mapping\Table(name: App\Entity\Aktenzeichen::TABLE_NAME)]
#[\Doctrine\ORM\Mapping\UniqueConstraint(name: 'aktenzeichen_idx', columns: ['aktenzeichen'])]
#[\Doctrine\ORM\Mapping\Entity]
class StringAnnotationValue
{
}

?>
