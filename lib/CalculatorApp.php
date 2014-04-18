<?php

namespace n00bsys0p;

require_once('HashCalculator.php');
require_once('ViewBuilder.php');

/**
 * Cryptocurrency Hash Calculator Application
 *
 * The container for the entire application. This should
 * make deployment as simple as instantiating the class
 * and calling run.
 */
class CalculatorApp
{
    protected     $config           = NULL;
    protected     $hashRate         = NULL;
    protected     $hashCalculator   = NULL;
    protected     $data             = array();
    protected     $output           = NULL;
    protected     $errors           = array();
    protected     $displayFormat    = 'html';
    public static $supportedFormats = array('html', 'json');

    /**
     * Constructor
     *
     * Set up the application configuration based on the
     * configuration array passed.
     *
     * @param array $config The application configuration
     */
    public function __construct($config)
    {
        $this->config = $config;

        $format = isset($_GET['fmt']) ? $_GET['fmt'] : NULL;
        if(!is_null($format))
            if(in_array($format, static::$supportedFormats))
                $this->displayFormat = $format;

        // Only instantiate the view builder if it's not an API request
        if($this->displayFormat == 'html')
           $this->viewBuilder = new ViewBuilder();

        /**
         * We use dependency injection to inject the correct
         * adaptor because we have to customise the block
         * reward subsidy for most different alt coins. You
         * can also choose to inject your own calculator.
         *
         * The adaptor can also optionally be an RPC based
         * adaptor, for quicker response times in a machine
         * that's already running a full node.
         */
        $hashCalculator = $this->config['calculator']['classname'];
        $adaptor = $this->config['adaptor']['classname'];

        $this->hashCalculator = new $hashCalculator($this->config, $adaptor);
    }

    /**
     * Run the application from start to finish
     *
     * Nothing more to say about this - it runs all the steps the app
     * requires to run from start to finish.
     */
    public function run()
    {
        // These must be run in this order
        try {
            $this->init();
            $this->requestData();
        } catch(\Exception $e) {
            $this->errors = $this->hashCalculator->getErrors();
            $this->errors []= $e->getMessage();
        }

        /**
         * TODO: Tidy this up to shell out to a new class as
         * this is still a little messy.
         */
        switch($this->displayFormat)
        {
        case 'html':
            $this->prepareView();
            break;
        case 'json':
            $this->prepareJson();
            break;
        }

        $this->displayContent();
    }

    /**
     * Initialise the calculator application
     *
     * This sanity checks and sets sane defaults for the
     * user-defined variables.
     */
    protected function init()
    {
        // Sanity checks
        $hashrate = isset($_GET[GET_PARAM_HASHRATE]) ? $_GET[GET_PARAM_HASHRATE] : 0;
        $multiplier = isset($_GET[GET_PARAM_MULTIPLIER]) ? $_GET[GET_PARAM_MULTIPLIER] : 1;

        if(!is_numeric($hashrate))
            $hashrate = 0;
        if(!is_numeric($multiplier))
            $multiplier = 1;

        $this->hashRate = $hashrate *= $multiplier;
    }

    /**
     * Retrieve the required data
     *
     * Passes the configured currencies to the calculator to get daily
     * earnings data on each. This must be run after the application has
     * been initialised to prepare the data for formatting.
     */
    protected function requestData()
    {
        $currencies = $this->config['currencies'];

        $this->data = $this->hashCalculator->calculateForHashRate($this->hashRate, array_keys($currencies));
    }

    /**
     * Prepare the displayed view
     *
     * This puts together all the currently prepared information into a browser
     * compatible display format. It uses the local ViewBuilder instance to
     * do this. This must be run after requestData, as before that there is no
     * data to prepare.
     */
    protected function prepareView()
    {
        $data = $this->prepareData();
        $appname = $this->config['appname'];

        /**
         * Process any errors that have occurred
         */
        $errors = '';
        if(!empty($data['errors']))
        {
            foreach($data['errors'] as $err)
            {
                $errors .= $this->viewBuilder->prepareError($err);
            }
        }

        /**
         * Loop through the fiat variables, generating the required HTML
         * to insert into the table - both for headings and values
         */
        $fiat_hdr = $this->viewBuilder->prepareFiatHeaders($this->config['currencies']);
        $fiat_val = $this->viewBuilder->prepareFiatValues($this->data['fiat_per_day']);

        /**
         * Prepare the main (non-fiat-specific) page content.
         */
        $url = '//' . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'];
        $body_vars = array(
            'URL' => $url,
            'COINCODE' => $data['coin']['code'],
            'HASHRATE' => explode(' ', $data['hashrate'])[0],
            'HASHSUFFIX' => explode(' ',$data['hashrate'])[1],
            'COINSPERDAY' => $this->data['coins_per_day'],
            'BTCPERDAY' => $this->data['btc_per_day'],
            'FIATPERDAY' => $fiat_val,
            'DIFF' => $data['difficulty'],
        );

        $body = $this->viewBuilder->prepareBody($body_vars);

        /**
         * Prepare the layout
         */
        $analytics_id = $this->config['analytics']['ua_id'];
        $analytics_url = $this->config['analytics']['ua_url'];
        $coin = $data['coin']['name'];
        $title = preg_replace('/\%NAME/', $coin, $appname);
        $page_vars = array(
            'TITLE' => $title,
            'BODY' => $body,
            'ERRORS' => $errors,
            'GA_UAID' => $analytics_id,
            'GA_URL' => $analytics_url,
        );

        $this->output = $this->viewBuilder->prepareLayout($page_vars);
    }

    /**
     * Return a nicely formatted array of all required data.
     *
     * This currently processes the data to be used in the JSON
     * response. 
     *
     * @return array
     */
    protected function prepareData()
    {
        $response = array();
        if(!empty($this->errors))
        {
            $response['errors'] = $this->errors;

            return $response;
        }

        $response['coin'] = $this->config['app']['coin'];
        $response['hashrate'] = ($this->hashRate) ?
            Calculator::formatHashRate($this->hashRate) :
            '0 Mh/s';
        $response['difficulty'] = $this->hashCalculator->getDifficulty();
        $respomse['daily_return'] = array();
        $response['daily_return']['coins'] = $this->data['coins_per_day'];
        $response['daily_return']['btc'] = $this->data['btc_per_day'];
        $response['daily_return']['fiat'] = array();

        foreach($this->config['currencies'] as $code => $symbol)
        {
            $response['daily_return']['fiat'][$code] = array(
                'symbol' => $symbol,
                'value' => $this->data['fiat_per_day'],
            );
        }

        return $response;
    }

    protected function prepareJson()
    {
        header('Content-type: application/json');
        $this->output = json_encode($this->prepareData());
    }

    /**
     * Display the content to the user.
     *
     * This function could be redefined to set custom headers in the
     * case of the application being run as an API rather than embedded
     * in a web page.
     */
    protected function displayContent()
    {
        echo $this->output;
    }

    protected function addError($error)
    {
        $this->errors []= $error;
    }
}
