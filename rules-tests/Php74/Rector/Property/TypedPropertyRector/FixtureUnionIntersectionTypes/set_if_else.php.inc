<?php

namespace Rector\Tests\Php74\Rector\Property\TypedPropertyRector\FixtureUnionIntersectionTypes;

class SetIfElse
{
    public $stringOrInteger = 'hi';

    public function setNumber()
    {
        if (mt_rand(0, 100)) {
            $this->stringOrInteger = 'hey';
        } else {
            $this->stringOrInteger = 1000;
        }
    }
}

?>
-----
<?php

namespace Rector\Tests\Php74\Rector\Property\TypedPropertyRector\FixtureUnionIntersectionTypes;

class SetIfElse
{
    public int|string $stringOrInteger = 'hi';

    public function setNumber()
    {
        if (mt_rand(0, 100)) {
            $this->stringOrInteger = 'hey';
        } else {
            $this->stringOrInteger = 1000;
        }
    }
}

?>
