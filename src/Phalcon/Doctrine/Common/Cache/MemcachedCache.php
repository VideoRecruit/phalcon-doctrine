<?php

namespace VideoRecruit\Phalcon\Doctrine\Common\Cache;

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

use Doctrine;
use Memcached;

/**
 * @author Nikolaj Pogněrebko
 */
class MemcachedCache extends Doctrine\Common\Cache\MemcachedCache
{

	/**
	 * MemcachedCache constructor.
	 *
	 * @param Memcached|NULL $memcached
	 */
	public function __construct(Memcached $memcached = NULL)
	{
		$this->setMemcached($memcached);
	}

}
