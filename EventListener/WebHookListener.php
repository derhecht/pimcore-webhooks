<?php
namespace WebHookBundle\EventListener;

use GuzzleHttp\Exception\TransferException;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\Notification\Service\NotificationService;
use Pimcore\Tool;
use GuzzleHttp\Client;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use WebHookBundle\Utils\ExportDataObject;

class WebHookListener {
    
    private $logger;

    public function __construct(ApplicationLogger $logger) {
        $this->logger = $logger;
    }
    
    public function onPreAdd (ElementEventInterface $e) {
        $this->handleChange($e, "preAdd");
    }

    public function onPostAdd (ElementEventInterface $e) {
        $this->handleChange($e, "postAdd");
    }

    public function onPostAddFailure (ElementEventInterface $e) {
        $this->handleChange($e, "postAddFailure");
    }

    public function onPreUpdate (ElementEventInterface $e) {
        $this->handleChange($e, "preUpdate");
    }

    public function onPostUpdate (ElementEventInterface $e) {
        $this->handleChange($e, "postUpdate");
    }

    public function onPostUpdateFailure (ElementEventInterface $e) {
        $this->handleChange($e, "postUpdateFailure");
    }

    public function onDeleteInfo (ElementEventInterface $e) {
        $this->handleChange($e, "deleteInfo");
    }

    public function onPreDelete (ElementEventInterface $e) {
        $this->handleChange($e, "preDelete");
    }

    public function onPostDelete (ElementEventInterface $e) {
        $this->handleChange($e, "postDelete");
    }

    public function onPostDeleteFailure (ElementEventInterface $e) {
        $this->handleChange($e, "postDeleteFailure");
    }

    public function onPostCopy (ElementEventInterface $e) {
        $this->handleChange($e, "postCopy");
    }

    public function onPostCsvItemExport (ElementEventInterface $e) {
        $this->handleChange($e, "postCsvItemExport");
    }

    public function handleChange(ElementEventInterface $e, $eventName) {
        
        if ($e instanceof DataObjectEvent) {
            $dataObject = $e->getObject();

            if($dataObject->getType() != "folder" && $dataObject->getPublished()) {
                $entityType = $dataObject->getClassName();
            } else {
                return 0;
            }

            $classesList = new ClassDefinition\Listing();
            $classesList->setCondition("name LIKE ?", ["WebHook"]);
            $classes = $classesList->load();

            if(count($classes)) { 

                $class = '\\Pimcore\\Model\\DataObject\\WebHook\\Listing';
                \Pimcore\Model\DataObject\AbstractObject::setHideUnpublished(true);
                $webHooksList = new \Pimcore\Model\DataObject\Webhook\Listing();
                $webHooksList->setCondition("EntityType LIKE ? AND ListenedEvent LIKE ?", ["%".$entityType."%", "%".$eventName."%"]);
                $webHooksList = $webHooksList->load();

                $webHooks = array();
                foreach($webHooksList as $webHook) {
                    if (in_array($eventName, $webHook->getListenedEvent())) {
                        if (in_array($entityType, $webHook->getEntityType())) {
                            $webHooks[] = $webHook;
                        }
                    }
                }

                if (count($webHooks)) {

                    $exportData = new ExportDataObject();
                    $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder(), new XmlEncoder()]);
                    $arrayData["dataObject"] = $exportData->getDataForObject($dataObject);
                    $arrayData["arguments"] = $e->getArguments();

                    $jsonContent = $serializer->serialize($arrayData, 'json');
                    $this->logger->debug($jsonContent);

                    foreach ($webHooks as $webHook) {
                        $url = $webHook->getURL();
                        if(null == $url) {
                            continue;
                        }

                        if ($webHook->getApikey() != null) {
                            $apiKey = $webHook->getApikey();
                        } else if ($webHookApiKey = \Pimcore\Model\WebsiteSetting::getByName('WebHookApi-key')){
                            $apiKey = $webHookApiKey->getData();
                        } else {
                            $apiKey = "no-apy-key-found";
                            $this->logger->warning("Web Hook error: no api-key key found \nEvent: ".$eventName." Class: ".$entityType."\nhost: ".$webHook->getURL().['relatedObject' => $dataObject->getId()]);
                        }

                        $usedPrivate = null;
                        if ($webHook->getPrivateKey() != null) {
                            openssl_sign($jsonContent, $signature, $webHook->getPrivateKey(), OPENSSL_ALGO_SHA1);
                            $signature = base64_encode($signature);
                            $usedPrivate = "Use web hook private key";
                        } else if ($webHookprivateKey = \Pimcore\Model\WebsiteSetting::getByName('WebHookPrivateKey')){
                            openssl_sign($jsonContent, $signature, $webHookprivateKey->getData(), OPENSSL_ALGO_SHA1);
                            $signature = base64_encode($signature);
                            $usedPrivate = "Use default private key";
                        }

                        $client = new Client();
                        $method = 'POST';
                        $headers = ['Content-Type' => 'application/json',
                                    "x-pimcore-listen-event" => $eventName,
                                    "x-pimcore-object" => $entityType,
                                    "x-api-key" => $apiKey,
                                ];

                        if ($usedPrivate != null) {
                            $headers[] = [
                                "x-pimcore-signature" => $signature,
                                "x-pimcore-used-private-key" => $usedPrivate
                            ];
                        }

                        /*
                         * May have a look at https://github.com/softonic/graphql-client for complex GraphQL use cases.
                         * */

                        //TODO: Make this generic!

                        /**
                         * Mutation Example for default example data model car
                         */
                        $mutation = <<<'MUTATION'
mutation($id: Int, $description: String) {
  updateCar(
    id: $id,
    input: {
      description: $description
    }){
    message
  }
}
MUTATION;

                        $variables = [
                            'id' => $dataObject->getId(),
                            'description' => $dataObject->get('description')
                        ];

                        //--- End of TODO block ---

                        $body = ['query' => $mutation];
                        $body['variables'] = $variables;

                        try {
                            $response = $client->request($method, $url, ['headers' => $headers, 'body' => json_encode($body, JSON_UNESCAPED_SLASHES)]);
                            
                            $messaggeData = array();
                            $messaggeData['title'] = "WebHook Error";
                            $messaggeData['message'] ="Web Hook request error:\nEvent: ".$eventName." Class: ".$entityType."\nhost: ".$webHook->getURL()."\nResponse: ".$response->getStatusCode();

                            if ($response->getStatusCode() != 200) {
                                $this->sendNotification($messaggeData, $dataObject->getUserModification());
                            }

                            if($response->getStatusCode() >=400 && $response->getStatusCode() <= 599) {
                                $this->logger->error($messaggeData['message'], ['relatedObject' => $dataObject->getId()]);
                            }
                            
                            $this->logger->info("Event: " . $eventName . " Class: " . $entityType . " object Id " . $dataObject->getId() . " host: " . $webHook->getURL() . " Response: " . $response->getStatusCode());
                        } catch (TransferException $e){
                            $this->logger->error("Web Hook request error: \nEvent: ".$eventName." Class: ".$entityType."\nhost: ".$webHook->getURL()."\nResponse: ".$e->getMessage(), ['relatedObject' => $dataObject->getId()]);
                        
                            $messaggeData = array();
                            $messaggeData['title'] = "WebHook Error: code ";
                            $messaggeData['message'] ="Web Hook request error:\nEvent: ".$eventName." Class: ".$entityType."\nhost: ".$webHook->getURL()."\nResponse: ".$e->getMessage();

                            $this->sendNotification($messaggeData, $dataObject->getUserModification());
                        
                        }
                    }
                }
           }
        }
    }

    public function sendNotification($messaggeData, $userId=0) {
        
        $notificationService = \Pimcore::getContainer()->get(NotificationService::class); 
        $notificationService->sendToUser(
            $userId,
            0,
            $messaggeData['title'],
            $messaggeData['message'],
        );
    }
}
