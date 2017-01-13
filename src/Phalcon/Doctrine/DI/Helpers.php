<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace VideoRecruit\Phalcon\Doctrine\DI;

/**
 * Configuration helpers.
 */
class Helpers
{

	/**
	 * Merges configurations. Left has higher priority than right one.
	 *
	 * @return array|string
	 */
	public static function merge($left, $right)
	{
		if (is_array($left) && is_array($right)) {
			foreach ($left as $key => $val) {
				if (is_int($key)) {
					$right[] = $val;
				} else {
					if (isset($right[$key])) {
						$val = static::merge($val, $right[$key]);
					}
					$right[$key] = $val;
				}
			}

			return $right;

		} elseif ($left === NULL && is_array($right)) {
			return $right;

		} else {
			return $left;
		}
	}
}
