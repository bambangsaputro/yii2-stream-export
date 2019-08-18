<?php

namespace bambang\export;

use InvalidArgumentException;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\i18n\Formatter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use yii\base\Component;

class StreamExport extends Component {

    public $models;
    public $columns = [];
    public $headers = [];
    public $fileName;
    public $format;
    public $setHeaderTitle = true;
    public $formatter;
    public $fileHandle;
    private $bufferSize = 1000;
    public $queryEach = 100;

    public function init() {
        parent::init();
        if (!isset($this->models)) {
            throw new InvalidConfigException('Invalid models');
        }
        if ($this->formatter == null) {
            $this->formatter = Yii::$app->getFormatter();
        } elseif (is_array($this->formatter)) {
            $this->formatter = Yii::createObject($this->formatter);
        }
        if (!$this->formatter instanceof Formatter) {
            throw new InvalidConfigException('The "formatter" property must be either a Format object or a configuration array.');
        }
    }

    public function DataColumn($model, $params = []) {
        $value = null;
        if (isset($params['value']) && $params['value'] !== null) {
            if (is_string($params['value'])) {
                $value = ArrayHelper::getValue($model, $params['value']);
            } else {
                $value = call_user_func($params['value'], $model, $this);
            }
        } elseif (isset($params['attribute']) && $params['attribute'] !== null) {
            $value = ArrayHelper::getValue($model, $params['attribute']);
        }

        if (isset($params['format']) && $params['format'] != null)
            $value = $this->formatter()->format($value, $params['format']);

        return $value;
    }

    public function getModelColumns($columns = []) {
        $_columns = [];
        foreach ($columns as $key => $value) {
            if (is_string($value)) {
                $value_log = explode(':', $value);
                $_columns[$key] = ['attribute' => $value_log[0]];

                if (isset($value_log[1]) && $value_log[1] !== null) {
                    $_columns[$key]['format'] = $value_log[1];
                }

                if (isset($value_log[2]) && $value_log[2] !== null) {
                    $_columns[$key]['header'] = $value_log[2];
                }
            } elseif (is_array($value)) {
                if (!isset($value['attribute']) && !isset($value['value'])) {
                    throw new InvalidArgumentException('Attribute or Value must be defined.');
                }
                $_columns[$key] = $value;
            }
        }

        return $_columns;
    }

    public function formatter() {
        if (!isset($this->formatter))
            $this->formatter = Yii::$app->getFormatter();

        return $this->formatter;
    }

    public function setHeaders() {
        return [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $this->getFileName() . '-' . \Yii::$app->formatter->asDate('now', 'yyyy-MM-dd') . '.csv',
        ];
    }

    public function getFileName() {
        $fileName = 'report';
        if (isset($this->fileName)) {
            $fileName = $this->fileName;
        }
        return $fileName;
    }

    public function initExport() {
        $this->fileHandle = fopen('php://output', 'w');
        fwrite($this->fileHandle, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
    }

    private function closeFile() {
        fclose($this->fileHandle);
    }

    public function export() {
        $columns = isset($this->columns) ? $this->getModelColumns($this->columns) : [];
        $headers = isset($this->headers) ? $this->headers : [];
        $models = $this->models;
        $response = new StreamedResponse(function ()use ($models, $columns) {
            $this->initExport();
            $i = 0;
            $hasHeader = false;
            foreach ($models->each($this->queryEach) as $model) {
                if (empty($columns)) {
                    $columns = $model->attributes();
                }
                if ($this->setHeaderTitle && !$hasHeader) {
                    $header = [];
                    foreach ($columns as $key => $column) {
                        if (is_array($column)) {
                            if (isset($column['header'])) {
                                $header [] = $column['header'];
                            } elseif (isset($column['attribute']) && isset($headers[$column['attribute']])) {
                                $header [] = $headers[$column['attribute']];
                            } elseif (isset($column['attribute'])) {
                                $header[] = $model->getAttributeLabel($column['attribute']);
                            }
                        } else {
                            if (isset($headers[$column])) {
                                $header [] = $headers[$column];
                            } else {
                                $header [] = $model->getAttributeLabel($column);
                            }
                        }
                    }
                    fputcsv($this->fileHandle, $header);
                    $hasHeader = true;
                }
                $row = [];
                foreach ($columns as $key => $column) {
                    if (is_array($column)) {
                        $row [] = $this->DataColumn($model, $column);
                    } else {
                        $row [] = $this->DataColumn($model, ['attribute' => $column]);
                    }
                }
                fputcsv($this->fileHandle, $row);
                if ($i % $this->bufferSize === 0) {
                    flush();
                }
                $i++;
            }
            $this->closeFile();
            exit();
        }, Response::HTTP_OK, $this->setHeaders());
        $response->send();
    }

}
