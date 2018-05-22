<?php
/**
 * Copyright (c) 2018.
 * @author Antony [leantony] Chacha
 */

namespace Leantony\Grid\Listeners;

use Excel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Leantony\Grid\Export\DefaultExporter;
use Leantony\Grid\Export\ExcelExport;
use Leantony\Grid\Export\ExcelExporter;
use Leantony\Grid\Export\HtmlExport;
use Leantony\Grid\Export\JsonExport;
use Leantony\Grid\Export\PdfExport;
use Leantony\Grid\GridInterface;
use Leantony\Grid\GridResources;

class DataExportHandler
{
    use GridResources;

    /**
     * Quick toggle to specify if the grid allows exporting of records
     *
     * @var bool
     */
    protected $allowsExporting = true;

    /**
     * The filename that would be exported
     *
     * @var string
     */
    protected $exportFilename;

    /**
     * Available columns for export
     *
     * @var array|null
     */
    protected $availableColumnsForExport = null;

    /**
     * DataExportHandler constructor.
     * @param GridInterface $grid
     * @param Request $request
     * @param $builder
     * @param $validTableColumns
     * @param $args
     */
    public function __construct(GridInterface $grid, Request $request, $builder, $validTableColumns, $args)
    {
        $this->grid = $grid;
        $this->request = $request;
        $this->query = $builder;
        $this->validGridColumns = $validTableColumns;
        $this->args = $args;
    }

    /**
     * Export the data
     *
     * @return Response
     * @throws \Exception
     * @throws \Throwable
     */
    public function export()
    {
        if ($this->wantsToExport()) {
            $param = $this->request->get($this->getGrid()->getGridExportParam());
            if (in_array($param, $this->getGrid()->getGridExportTypes())) {
                return $this->exportAs($param);
            }
        }
    }

    /**
     * Check if the user wants to export data
     *
     * @return bool
     */
    protected function wantsToExport(): bool
    {
        return $this->getRequest()->has($this->getGrid()->getGridExportParam()) && $this->allowsExporting;
    }

    /**
     * Download export data
     *
     * @param string $type any of an allowed type in configuration
     * @return Response
     * @throws \Throwable
     */
    public function exportAs($type = 'xlsx')
    {
        switch ($type) {
            case 'pdf':
                {
                    return (new PdfExport())->export($this->getExportData(), [
                        'exportableColumns' => $this->getExportableColumns()[1],
                        'fileName' => $this->getFileNameForExport(),
                        'exportView' => $this->getGridExportView(),
                        'title' => $this->getGrid()->getName() . ' PDF report data'
                    ]);
                }
            case 'csv':
            case 'xlsx':
                {
                    $columns = $this->getExportableColumns()[1];
                    // headings
                    $headings = $columns->map(function ($col) {
                        return $col->name;
                    })->toArray();

                    return (new ExcelExport([
                        'title' => $this->getGrid()->getName(),
                        'columns' => $columns,
                        'data' => $this->getExportData(),
                        'headings' => $headings,
                    ]))->download($this->getFileNameForExport() . '.' . $type);
                }
            case 'html':
                {
                    return (new HtmlExport())->export($this->getExportData(), [
                        'exportableColumns' => $this->getExportableColumns()[1],
                        'fileName' => $this->getFileNameForExport(),
                        'exportView' => $this->getGridExportView(),
                        'title' => $this->getGrid()->getName() . ' HTML report data'
                    ]);
                }
            case 'json':
                {
                    return (new JsonExport())->export($this->getExportData(['doNotFormatKeys' => true]), [
                        'fileName' => $this->getFileNameForExport(),
                    ]);
                }
            default:
                throw new \InvalidArgumentException("unknown export type");
        }
    }

    /**
     * Get exportable columns by skipping the ones that were not requested
     *
     * @return array
     * @throws \Exception
     */
    protected function getExportableColumns(): array
    {
        if ($this->availableColumnsForExport !== null) {
            return $this->availableColumnsForExport;
        }

        $pinch = [];
        $availableColumns = $this->getColumnsToExport();
        $columns = collect($availableColumns)->reject(function ($column) use (&$pinch) {
            // reject all columns that have been set as not exportable
            $canBeSkipped = !$column->export;
            if (!$canBeSkipped) {
                // add this to an array to be used for granular filtering of the query
                $pinch[] = $column->key;
            }
            return $canBeSkipped;
        });
        $this->availableColumnsForExport = [$pinch, $columns];
        return $this->availableColumnsForExport;
    }

    /**
     * Gets the columns to be exported
     *
     * @return array
     * @throws \Exception
     */
    public function getColumnsToExport(): array
    {
        return $this->getGrid()->getProcessedColumns();
    }

    /**
     * Filename for export
     *
     * @return string
     */
    public function getFileNameForExport(): string
    {
        $this->exportFilename = Str::slug($this->getGrid()->getName()) . '-' . time();
        return $this->exportFilename;
    }

    /**
     * Get the data to be exported
     *
     * @param array $params
     * @return Collection
     * @throws \Exception
     */
    public function getExportData(array $params = []): Collection
    {
        // in some special cases, we would need to preserve key names as they were on the model itself
        // for example, exporting data as JSON
        $doNotFormatKeys = $params['doNotFormatKeys'] ?? false;

        // the pinch contains the columns the user wants to export as per their configuration
        // the columns are the actual processed column objects
        list($pinch, $columns) = $this->getExportableColumns();

        $records = new Collection();

        // we do a select query of the columns the user marked as exportable
        // this means that for now, custom columns would not be exported
        // then process those columns in chunks of a configured size
        $this->getQuery()->select($pinch)->chunk($this->getGridExportQueryChunkSize(), function ($items) use ($columns, $params, $doNotFormatKeys, $records) {
            // customize the results
            $columns = $columns->toArray();

            // we run a map over each item from the chunk and run a formatter function over it
            // the formatter function takes into account the various user defined customizations for
            // each column entry
            $data = $items->map(function ($value) use ($columns, $params, $doNotFormatKeys) {
                return call_user_func([$this, 'dataFormatter'], $value, $columns, $doNotFormatKeys);
            });
            // once we are done, we add the data to a collection
            $records->push($data);
        });

        // the collection will have a single nested one inside it, so we take that one
        return $records[0];
    }

    /**
     * Format data for export
     *
     * @param mixed $item
     * @param array $columns
     * @param boolean $doNotFormatKeys
     * @return array
     */
    protected function dataFormatter($item, array $columns, bool $doNotFormatKeys): array
    {
        $data = [];
        foreach ($columns as $column) {
            // render as per requested on each column
            // `processColumns()` would have already taken care of adding the user defined callbacks
            // so here, we call those callbacks with the required arguments
            if (is_callable($column->data)) {
                $key = $doNotFormatKeys ? $column->key : $column->name;
                $value = call_user_func($column->data, $item, $column->key);
                array_push($data, [$key => $value]);
            } else {
                $key = $doNotFormatKeys ? $column->key : $column->name;
                $value = $item->{$column->key};
                array_push($data, [$key => $value]);
            }
        }
        // collapse the data to a 1d array
        return collect($data)->collapse()->toArray();
    }
}