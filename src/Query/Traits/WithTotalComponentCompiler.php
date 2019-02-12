<?php
/**
 * Created by PhpStorm.
 * User: olegtytarenko
 * Date: 2019-01-09
 * Time: 21:22
 */

namespace Tinderbox\ClickhouseBuilder\Query\Traits;

use Tinderbox\ClickhouseBuilder\Query\BaseBuilder as Builder;

trait WithTotalComponentCompiler
{
    public function compileWithTotalComponent(Builder $builder, $withTotal = false): string
    {
        if(!$withTotal) {
            return "";
        }

        return "WITH TOTALS";
    }
}