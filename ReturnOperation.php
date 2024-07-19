<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;
use MessagesClient;
use NotificationEvents;
use NotificationManager;
use Seller;
use Contractor;
use Employee;
use Status;

/**
 * Класс для выполнения операции уведомления о возврате товаров
 */
class TsReturnOperation extends ReferencesOperation
{
    // Константы для типов уведомлений
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * Выполнение операции уведомления
     * 
     * @return array Результат выполнения операции
     * @throws Exception В случае ошибок в данных или при выполнении операций
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = (int)($data['resellerId'] ?? 0);
        $notificationType = (int)($data['notificationType'] ?? 0);

        $result = $this->initializeResult();

        $this->validateInputData($resellerId, $notificationType);

        $reseller = $this->getReseller($resellerId);
        $client = $this->getClient($data, $resellerId);
        $cr = $this->getEmployee((int)($data['creatorId'] ?? 0), 'Creator not found!');
        $et = $this->getEmployee((int)($data['expertId'] ?? 0), 'Expert not found!');

        $differences = $this->getDifferences($notificationType, $data, $resellerId);

        $templateData = $this->prepareTemplateData($data, $cr, $et, $client, $differences);

        $this->validateTemplateData($templateData);

        $this->sendEmployeeNotifications($resellerId, $templateData, $result);
        $this->sendClientNotifications($notificationType, $data, $resellerId, $client, $templateData, $result);

        return $result;
    }

    /**
     * Инициализация результата
     * 
     * @return array
     */
    private function initializeResult(): array
    {
        return [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];
    }

    /**
     * Валидация входных данных
     * 
     * @param int $resellerId
     * @param int $notificationType
     * @throws Exception
     */
    private function validateInputData(int $resellerId, int $notificationType): void
    {
        if (empty($resellerId)) {
            throw new Exception('Empty resellerId', 400);
        }

        if (empty($notificationType)) {
            throw new Exception('Empty notificationType', 400);
        }
    }

    /**
     * Получение данных реселлера
     * 
     * @param int $resellerId
     * @return Seller
     * @throws Exception
     */
    private function getReseller(int $resellerId): Seller
    {
        $reseller = Seller::getById($resellerId);
        if ($reseller === null) {
            throw new Exception('Seller not found!', 400);
        }
        return $reseller;
    }

    /**
     * Получение данных клиента
     * 
     * @param array $data
     * @param int $resellerId
     * @return Contractor
     * @throws Exception
     */
    private function getClient(array $data, int $resellerId): Contractor
    {
        $clientId = (int)($data['clientId'] ?? 0);
        $client = Contractor::getById($clientId);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new Exception('Client not found!', 400);
        }
        return $client;
    }

    /**
     * Получение данных сотрудника
     * 
     * @param int $employeeId
     * @param string $errorMessage
     * @return Employee
     * @throws Exception
     */
    private function getEmployee(int $employeeId, string $errorMessage): Employee
    {
        $employee = Employee::getById($employeeId);
        if ($employee === null) {
            throw new Exception($errorMessage, 400);
        }
        return $employee;
    }

    /**
     * Определение различий в статусах
     * 
     * @param int $notificationType
     * @param array $data
     * @param int $resellerId
     * @return string
     */
    private function getDifferences(int $notificationType, array $data, int $resellerId): string
    {
        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)($data['differences']['from'] ?? 0)),
                'TO'   => Status::getName((int)($data['differences']['to'] ?? 0)),
            ], $resellerId);
        }
        return $differences;
    }

    /**
     * Подготовка данных для шаблона уведомления
     * 
     * @param array $data
     * @param Employee $cr
     * @param Employee $et
     * @param Contractor $client
     * @param string $differences
     * @return array
     */
    private function prepareTemplateData(array $data, Employee $cr, Employee $et, Contractor $client, string $differences): array
    {
        return [
            'COMPLAINT_ID'       => (int)($data['complaintId'] ?? 0),
            'COMPLAINT_NUMBER'   => (string)($data['complaintNumber'] ?? ''),
            'CREATOR_ID'         => (int)($data['creatorId'] ?? 0),
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)($data['expertId'] ?? 0),
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)($data['clientId'] ?? 0),
            'CLIENT_NAME'        => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID'     => (int)($data['consumptionId'] ?? 0),
            'CONSUMPTION_NUMBER' => (string)($data['consumptionNumber'] ?? ''),
            'AGREEMENT_NUMBER'   => (string)($data['agreementNumber'] ?? ''),
            'DATE'               => (string)($data['date'] ?? ''),
            'DIFFERENCES'        => $differences,
        ];
    }

    /**
     * Проверка наличия всех данных для шаблона
     * 
     * @param array $templateData
     * @throws Exception
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    /**
     * Отправка email уведомлений сотрудникам
     * 
     * @param int $resellerId
     * @param array $templateData
     * @param array &$result
     */
    private function sendEmployeeNotifications(int $resellerId, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    /**
     * Отправка уведомлений клиенту
     * 
     * @param int $notificationType
     * @param array $data
     * @param int $resellerId
     * @param Contractor $client
     * @param array $templateData
     * @param array &$result
     */
    private function sendClientNotifications(int $notificationType, array $data, int $resellerId, Contractor $client, array $templateData, array &$result): void
    {
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            $emailFrom = getResellerEmailFrom($resellerId);

            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)($data['differences']['to'] ?? 0));
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)($data['differences']['to'] ?? 0), $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }
    }
}
