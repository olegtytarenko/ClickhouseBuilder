<?php
/**
 * Created by PhpStorm.
 * User: otytar
 * Date: 2019-02-12
 * Time: 11:53
 */

namespace Tinderbox\ClickhouseBuilder\Query\Traits;

use Tinderbox\ClickhouseBuilder\Query\BaseBuilder as Builder;

trait SelectDictGetUInt64
{
    public function dictGetUInt64($builder, array $params) : string {
        list($name, $keyFind, $keyMatch) = $params;
        return "dictGetUInt64('{$name}', '{$keyFind}', toUInt64(`{$keyMatch}`))";
    }
}