<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use KimaiPlugin\ProRataTimeExportBundle\Model\Money;

return static function (Money $money, mixed $factor): Money {
    return $money->multiply($factor);
};
