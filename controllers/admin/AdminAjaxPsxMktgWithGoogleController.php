<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use PrestaShop\Module\PsxMarketingWithGoogle\Adapter\ConfigurationAdapter;
use PrestaShop\Module\PsxMarketingWithGoogle\Config\Config;
use PrestaShop\Module\PsxMarketingWithGoogle\Handler\ErrorHandler\ErrorHandler;
use PrestaShop\Module\PsxMarketingWithGoogle\Provider\CarrierDataProvider;
use PrestaShop\Module\PsxMarketingWithGoogle\Provider\GoogleTagProvider;
use PrestaShop\Module\PsxMarketingWithGoogle\Repository\CountryRepository;
use PrestaShop\Module\PsxMarketingWithGoogle\Repository\CurrencyRepository;
use PrestaShop\Module\PsxMarketingWithGoogle\Repository\ProductRepository;
use PrestaShop\ModuleLibFaq\Faq;

class AdminAjaxPsxMktgWithGoogleController extends ModuleAdminController
{
    /** @var PsxMarketingWithGoogle */
    public $module;

    /**
     * @var ConfigurationAdapter
     */
    private $configurationAdapter;

    /**
     * @var CountryRepository
     */
    private $countryRepository;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var GoogleTagProvider
     */
    private $googleTagProvider;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = false;

        $this->module->getService(ErrorHandler::class);
        $this->configurationAdapter = $this->module->getService(ConfigurationAdapter::class);
        $this->countryRepository = $this->module->getService(CountryRepository::class);
        $this->productRepository = $this->module->getService(ProductRepository::class);
        $this->googleTagProvider = $this->module->getService(GoogleTagProvider::class);
        $this->currencyRepository = $this->module->getService(CurrencyRepository::class);
        $this->ajax = true;
    }

    public function initContent()
    {
        parent::initContent();
    }

    public function displayAjax()
    {
        $inputs = json_decode(Tools::file_get_contents('php://input'), true);
        $action = isset($inputs['action']) ? $inputs['action'] : null;
        switch ($action) {
            case 'setWebsiteVerificationMeta':
                $this->setWebsiteVerificationMeta($inputs);
                break;
            case 'getCarrierValues':
                $this->getCarrierValues();
                break;
            case 'getProductsReadyToSync':
                $this->getProductsReadyToSync($inputs);
                break;
            case 'getShopConfigurationForGMC':
                $this->getShopConfigurationForGMC();
                break;
            case 'getWebsiteRequirementStatus':
                $this->getWebsiteRequirementStatus();
                break;
            case 'setWebsiteRequirementStatus':
                $this->setWebsiteRequirementStatus($inputs);
                break;
            case 'toggleGoogleAccountIsRegistered':
                $this->toggleGoogleAccountIsRegistered($inputs);
                break;
            case 'retrieveFaq':
                $this->retrieveFaq();
                break;
            case 'getShopConfigurationForAds':
                $this->getShopConfigurationForAds();
                break;
            case 'getRemarketingTagsStatus':
                $this->getRemarketingTagsStatus();
                break;
            case 'toggleRemarketingTags':
                $this->toggleRemarketingTags($inputs);
                break;
            case 'checkRemarketingTagExists':
                $this->checkRemarketingTagExists($inputs);
                break;
            default:
                http_response_code(400);
                $this->ajaxDie(json_encode(['success' => false, 'message' => $this->l('Action is missing or incorrect.')]));
        }
    }

    private function setWebsiteVerificationMeta(array $inputs)
    {
        if (!isset($inputs['websiteVerificationMeta'])) {
            http_response_code(400);
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Missing Meta key',
            ]));
        }
        $websiteVerificationMeta = $inputs['websiteVerificationMeta'];

        if ($websiteVerificationMeta === false) {
            $this->configurationAdapter->deleteByName(Config::PSX_MKTG_WITH_GOOGLE_WEBSITE_VERIFICATION_META);
            $this->ajaxDie(json_encode(['success' => true, 'method' => 'delete']));
        } else {
            // base64 encoded to avoid prestashop sanitization
            $this->configurationAdapter->updateValue(
                Config::PSX_MKTG_WITH_GOOGLE_WEBSITE_VERIFICATION_META,
                base64_encode($websiteVerificationMeta),
            );
            $this->ajaxDie(json_encode(['success' => true, 'method' => 'insert']));
        }
    }

    private function getProductsReadyToSync(array $inputs)
    {
        $this->ajaxDie(json_encode([
            'total' => $this->productRepository->getProductsTotal(
                $this->context->shop->id,
                ['onlyActive' => true]
            ),
        ]));
    }

    private function getShopConfigurationForGMC()
    {
        $data = [
            'shop' => [
                'name' => Shop::isFeatureActive()
                    ? $this->context->shop->name
                    : $this->configurationAdapter->get('PS_SHOP_NAME'),
                'url' => $this->context->link->getBaseLink($this->context->shop->id),
            ],
            'store' => [
                /*
                 * Based on structure available on Google documentation
                 * @see https://developers.google.com/shopping-content/reference/rest/v2.1/accounts#AccountAddress
                 */
                'streetAddress' => trim($this->configurationAdapter->get('PS_SHOP_ADDR1')
                    . ' '
                    . $this->configurationAdapter->get('PS_SHOP_ADDR2')),
                'locality' => $this->configurationAdapter->get('PS_SHOP_CITY'),
                'postalCode' => $this->configurationAdapter->get('PS_SHOP_CODE'),
                'country' => $this->countryRepository->getShopDefaultCountry(),
                'phone' => $this->configurationAdapter->get('PS_SHOP_PHONE'),
            ],
        ];

        if ($this->countryRepository->countryNeedState() === true) {
            $data['store']['region'] = State::getNameById($this->configurationAdapter->get('PS_SHOP_STATE_ID'));
        }

        $this->ajaxDie(json_encode($data));
    }

    /**
     * Registering the GOOGLE ACCOUNT link in the shop database allows us to know if there
     * will be a conflict with another shop using the same domain name.
     */
    private function toggleGoogleAccountIsRegistered(array $inputs)
    {
        if (!isset($inputs['isGoogleAccountLinked'])) {
            http_response_code(400);
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Missing isGoogleAccountLinked key',
            ]));
        }

        if ((bool) $inputs['isGoogleAccountLinked']) {
            $this->configurationAdapter->updateValue(Config::PSX_MKTG_WITH_GOOGLE_ACCOUNT_IS_LINKED, true);
        } else {
            $this->configurationAdapter->deleteByName(Config::PSX_MKTG_WITH_GOOGLE_ACCOUNT_IS_LINKED);
        }
        $this->ajaxDie(json_encode(['success' => true]));
    }

    private function getCarrierValues()
    {
        /** @var CarrierDataProvider $carrierDataProvider */
        $carrierDataProvider = $this->module->getService(CarrierDataProvider::class);

        $carrierLines = $carrierDataProvider->getFormattedData();

        $this->ajaxDie(json_encode($carrierLines));
    }

    private function getWebsiteRequirementStatus()
    {
        $requirements = json_decode($this->configurationAdapter->get(Config::PSX_MKTG_WITH_GOOGLE_WEBSITE_REQUIREMENTS_STATUS))
            ?: [];

        $this->ajaxDie(json_encode([
            'requirements' => $requirements,
        ]));
    }

    private function setWebsiteRequirementStatus(array $inputs)
    {
        if (!isset($inputs['requirements']) || !is_array($inputs['requirements'])) {
            http_response_code(400);
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Missing requirements key or value must be an array',
            ]));
        }

        $requirements = $inputs['requirements'];

        $allowedKeys = [
            'shoppingAdsPolicies',
            'accurateContactInformation',
            'secureCheckoutProcess',
            'returnPolicy',
            'billingTerms',
            'completeCheckoutProcess',
        ];

        foreach ($requirements as $value) {
            if (!in_array($value, $allowedKeys)) {
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => 'Unknown requirement key ' . $value,
                ]));
            }
        }

        $this->configurationAdapter->updateValue(
            Config::PSX_MKTG_WITH_GOOGLE_WEBSITE_REQUIREMENTS_STATUS,
            json_encode($requirements)
        );

        $this->ajaxDie(json_encode(['success' => true]));
    }

    public function getShopConfigurationForAds()
    {
        $defaultTimeZone = date_default_timezone_get();
        $timeZone = new DateTime('now', new DateTimeZone($defaultTimeZone));
        $textWithTimeZone = "(UTC {$timeZone->format('P')}) {$defaultTimeZone}";

        $this->ajaxDie(json_encode([
            'timezone' => [
                'offset' => $timeZone->format('P'),
                'text' => $textWithTimeZone,
            ],
            'currency' => $this->currencyRepository->getShopCurrency()['isoCode'],
        ]));
    }

    private function getRemarketingTagsStatus()
    {
        $this->ajaxDie(json_encode([
            'remarketingTagsStatus' => $this->configurationAdapter->get(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS),
        ]));
    }

    private function toggleRemarketingTags(array $inputs)
    {
        if (!isset($inputs['isRemarketingEnabled']) || !isset($inputs['tagSnippet'])) {
            http_response_code(400);
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Missing isRemarketingEnabled or tagSnippet key',
            ]));
        }

        if ((bool) $inputs['isRemarketingEnabled']) {
            $this->configurationAdapter->updateValue(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS, true);
            $this->configurationAdapter->updateValue(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_TAG, base64_encode($inputs['tagSnippet']));
        } else {
            $this->configurationAdapter->deleteByName(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS);
            $this->configurationAdapter->deleteByName(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_TAG);
        }
        $this->ajaxDie(json_encode(['success' => true]));
    }

    private function checkRemarketingTagExists(array $inputs)
    {
        if (!isset($inputs['tag'])) {
            http_response_code(400);
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Missing tag key',
            ]));
        }

        $googleRemarketingStatus = $this->configurationAdapter->get(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS);
        if ($googleRemarketingStatus) {
            $this->configurationAdapter->updateValue(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS, false);
        }

        $googleTag = $this->googleTagProvider->checkGoogleTagAlreadyExists($inputs['tag'], $this->context->shop->id);

        if (false === $googleTag && $googleRemarketingStatus) {
            $this->configurationAdapter->updateValue(Config::PSX_MKTG_WITH_GOOGLE_REMARKETING_STATUS, true);
        }

        $this->ajaxDie(json_encode(['tagAlreadyExists' => $googleTag]));
    }

    /**
     * {@inheritdoc}
     */
    protected function ajaxDie($value = null, $controller = null, $method = null)
    {
        header('Content-Type: application/json');
        parent::ajaxDie($value, $controller, $method);
    }

    /**
     * Retrieve the faq
     */
    public function retrieveFaq()
    {
        $faq = new Faq($this->module->module_key, _PS_VERSION_, $this->context->language->iso_code);

        $this->ajaxDie(
            json_encode(
                [
                    'faq' => $faq->getFaq(),
                    'doc' => $this->getUserDocumentation(),
                    'contactUs' => 'support-google@prestashop.com',
                ]
            )
        );
    }

    /**
     * Get the documentation url depending on the current language
     *
     * @return string
     */
    private function getUserDocumentation()
    {
        $isoCode = $this->context->language->iso_code;
        $baseUrl = 'https://storage.googleapis.com/psessentials-documentation/' . $this->module->name;

        if (!$this->checkFileExist($baseUrl . '/user_guide_' . $isoCode . '.pdf')) {
            $isoCode = 'en';
        }

        return $baseUrl . '/user_guide_' . $isoCode . '.pdf';
    }

    /**
     * Use cUrl to get HTTP headers and detect any HTTP 404
     *
     * @param string $docUrl
     *
     * @return bool
     */
    private function checkFileExist($docUrl)
    {
        $ch = curl_init($docUrl);

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $retcode < 400;
    }
}
