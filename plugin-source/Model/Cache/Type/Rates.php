<?php
/**
 * Cache type for checkout rates.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * "skydropx_rates" cache type. When the merchant disables it, the pool's access proxy turns it into a no-op.
 */
class Rates extends TagScope
{
    public const TYPE_IDENTIFIER = 'skydropx_rates';
    public const CACHE_TAG = 'SKYDROPX_RATES';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
