<?php
require_once __DIR__.'/../includes/deployment_settings_helper.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: public, max-age=60');header('X-Content-Type-Options: nosniff');echo json_encode(['success'=>true,'data'=>deploymentPublicBranding()],JSON_THROW_ON_ERROR);
