<?php 

namespace Aftermarketpl\CompanyLookup;

use Throwable;
use Aftermarketpl\CompanyLookup\Exceptions\GusReaderException;
use Aftermarketpl\CompanyLookup\Models\CompanyAddress;
use Aftermarketpl\CompanyLookup\Models\CompanyData;
use Aftermarketpl\CompanyLookup\Models\CompanyIdentifier;

use SoapClient;

use GusApi\BulkReportTypes;
use GusApi\Exception\InvalidUserKeyException;
use GusApi\Exception\NotFoundException;
use GusApi\GusApi;
use GusApi\ReportTypes;
use DateTimeImmutable;
use GusApi\SearchReport;

/**
 * https://api.stat.gov.pl/Home/RegonApi/
 */
class GusReader
{
    use Traits\ResolvesVatid;
    use Traits\ValidatesVatid;

    /**
     * API key
     */
    private $apikey = '';
    private $api = null;

    private $options = [];
    
    /**
     * Handle current report
     */
    private $report = false;


    /**
     * 
     */
    public function __construct(string $apikey = '')
    {
        $this->apikey = $apikey;

        try {
            $this->api = new GusApi($apikey);
            $this->api->login();
        } catch(\Throwable $e) {
            throw new GusReaderException('Checking status currently not available');
        }
        
    }

    /**
     * Lookup company by vatid
     */
    public function lookup(string $vatid)
    {
        $vatid = $this->validateVatid($vatid, 'PL');
        list($country, $number) = $this->resolveVatid($vatid);
        
        try {
            $gusReports = $this->api->getByNip($number);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive
                
                $this->report = $gusReport;
                return $this->mapCompanyData($gusReport);
            }

        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier('vat', $number);
            $companyData->valid = false;
            return $companyData;
        }

        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier('vat', $number);
        $companyData->valid = false;
        return $companyData;        
    }

    /**
     * Lookup company by KRS
     */
    public function lookupKRS(string $krs)
    {
        $companyData = new CompanyData;


        try {
            $gusReports = $this->api->getByKrs($krs);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive
                
                $this->report = $gusReport;
                $companyData =  $this->mapCompanyData($gusReport);
                $companyData->identifiers[] = new CompanyIdentifier('krs', $krs);
                return $companyData;
            }

        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier('krs', $krs);
            $companyData->valid = false;
            return $companyData;        
        }

        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier('krs', $krs);
        $companyData->valid = false;
        return $companyData;         
    }

    /**
     * Lookup company by REGON
     */
    public function lookupREGON(string $regon)
    {
        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier('regon', $regon);

        try {
            $gusReports = $this->api->getByRegon($regon);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive

                $this->report = $gusReport;
                return $this->mapCompanyData($gusReport);
            }

        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier('regon', $regon);
            $companyData->valid = false;
            return $companyData;       
        }

        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier('regon', $regon);
        $companyData->valid = false;
        return $companyData;         
    }


    /**
     * 
     */
    protected function mapCompanyData(SearchReport $gusReport) {
        $companyAddress = new CompanyAddress;
        $companyAddress->country = 'PL';
        $companyAddress->postalCode = (string) $gusReport->getZipCode();
        $companyAddress->address = (string) $gusReport->getStreet().' '.$gusReport->getPropertyNumber() . ( $gusReport->getApartmentNumber() ? '/'.$gusReport->getApartmentNumber() : '');
        $companyAddress->city = (string) $gusReport->getCity();

        $companyData = new CompanyData;
        $companyData->valid = true;
        $companyData->name = (string) $gusReport->getName();
        
        $companyData->identifiers = [];
        $companyData->identifiers[] = new CompanyIdentifier('vat', $gusReport->getNip());
        $companyData->identifiers[] = new CompanyIdentifier('regon', $gusReport->getRegon());

        $companyData->mainAddress = $companyAddress;

        $companyData->pkdCodes = array_map(function($v){
            return $v['praw_pkdKod'];
        }, $this->fetchPKD());

        return $companyData;
    }

    private function fetchPKD() {
        if(! ($this->report instanceof SearchReport)) {
            throw new GusReaderException('No company, please lookup company');
        }
        
        switch($this->report->getType())
        {
            case 'p': // osoba prawna
                $reportType = ReportTypes::REPORT_ACTIVITY_LAW_PUBLIC;
                break;

            case 'f': // osoba fizyczna
                $reportType = ReportTypes::REPORT_LOCALS_PHYSIC_PUBLIC;
                break; 
            
            default:
                throw new GusReaderException('Uknown company type');
        }
        
        return $this->api->getFullReport($this->report, $reportType);
    }

    /**
     * 
     */
    protected function handleFullReport(SearchReport $report) {
        switch($report->getType())
        {
            case 'p': // osoba prawna
                $reportType = ReportTypes::REPORT_PUBLIC_LAW;
                break;

            case 'f': // osoba fizyczna
                $reportType = ReportTypes::REPORT_ACTIVITY_PHYSIC_PERSON;
                break;                    
        }
        return $this->api->getFullReport($report, $reportType);
    }
}