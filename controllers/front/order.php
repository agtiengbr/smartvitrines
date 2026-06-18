<?php

class SmartvitrinesOrderModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    public function initContent()
    {
        parent::initContent();

        /** @var smartvitrines|false $module */
        $module = $this->module;
        if (!$module instanceof smartvitrines) {
            $this->respondJson(['error' => 'module unavailable'], 500);

            return;
        }

        if (!$module->validateWorkerAuth()) {
            $this->respondJson(['error' => 'unauthorized'], 401);

            return;
        }

        $orderId = (int) Tools::getValue('id');
        if ($orderId <= 0) {
            $this->respondJson(['error' => 'missing id'], 400);

            return;
        }

        $payload = $module->buildOrderPayload($orderId);
        if ($payload === null) {
            $this->respondJson(['error' => 'not found'], 404);

            return;
        }

        $this->respondJson($payload, 200);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respondJson(array $data, int $statusCode): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($data);
        exit;
    }
}
