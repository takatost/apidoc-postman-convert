<?php
/**
 * Created by PhpStorm.
 * User: JohnWang <i@takato.st>
 * Date: 2018/6/26
 * Time: 15:59
 */

namespace Takatost\ApidocPostmanConvert\Console;

use League\HTMLToMarkdown\HtmlConverter;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConvertCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('convert')
            ->setDescription('Convert apidoc to postman.')
            ->addArgument('path', InputArgument::OPTIONAL, '', getcwd());
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Converting...</info>');
        $path = $input->getArgument('path');
        $apiProjectFile = $path . '/api_project.json';
        $apiDataFile = $path . '/api_data.json';

        $projectArray = [];
        if (file_exists($apiProjectFile)) {
            $projectJson = file_get_contents($apiProjectFile);
            $projectArray = json_decode($projectJson, true);
        }

        if (!file_exists($apiDataFile)) {
            throw new RuntimeException('The apidoc json file not exist.');
        }

        $dataJson = file_get_contents($apiDataFile);
        $dataArray = json_decode($dataJson, true);

        $postmanJson = $this->convert($dataArray, $projectArray);

        file_put_contents($path . '/postman_collection.json', $postmanJson);

        $output->writeln('<comment>DONE.</comment>');
    }

    protected function convert($dataArray, $projectArray = [])
    {
        $postmanArray = [
            "collection" => [
                "variables" => [],
                "info"      => [
                    "name"        => "API 文档",
                    "schema"      => "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
                    "description" => ''
                ]
            ]
        ];

        if ($projectArray) {
            $postmanArray['collection']['info']['name'] = $projectArray['name'] ? $projectArray['name'] : 'API 文档';
            $postmanArray['collection']['info']['version'] = $projectArray['version'];


            if (isset($projectArray['header']['content'])) {
                $converter = new HtmlConverter();
                $html = $projectArray['header']['content'];
                $markdown = $converter->convert($html);
                $postmanArray['collection']['info']['description'] = '# ' . $projectArray['header']['title'] . "\n" . $markdown . "\n\n";
            }
        }

        $postmanArray['collection']['info']['description'] .= "# API 参考";

        $groups = [];
        foreach ($dataArray as $item) {
            $group = trim($item['group']);
            $groups[ $group ][] = $item;
        }

        $postmanItems = [];
        foreach ($groups as $group => $groupItems) {
            $postmanFolder = [
                'name'        => $group,
                "description" => ""
            ];

            $postmanFolderItems = [];
            foreach ($groupItems as $item) {
                $method = strtoupper($item['type']);
                $postmanFolderItem = [
                    'name'    => $item['title'],
                    'request' => [
                        'url'         => [
                            "host" => "{{host}}",
                            'path' => $item['url']
                        ],
                        'method'      => $method,
                        'header'      => [
                            [
                                "key"   => "Accept",
                                "value" => "application/json"
                            ]
                        ],
                        'description' => isset($item['description']) ? str_replace(['<p>', '</p>'], '', $item['description']) : "",
                    ]
                ];

                if (isset($item['parameter'])) {
                    if ($method === 'GET') {
                        $queries = [];

                        foreach (reset($item['parameter']['fields']) as $query) {
                            $queries[] = [
                                'key'         => $query['field'],
                                'value'       => isset($query['defaultValue']) ? $query['defaultValue'] : '',
                                'description' => str_replace(['<p>', '</p>'], '', $query['description']),
                            ];
                        }

                        $postmanFolderItem['request']['url']['query'] = $queries;
                    } else {
                        $queries = [];

                        foreach (reset($item['parameter']['fields']) as $query) {
                            $queries[] = [
                                'key'         => $query['field'],
                                'value'       => isset($query['defaultValue']) ? $query['defaultValue'] : '',
                                "type"        => "text",
                                'description' => str_replace(['<p>', '</p>'], '', $query['description']),
                            ];
                        }

                        $postmanFolderItem['header'] = [
                            [
                                "key"   => "Content-Type",
                                "value" => "application/x-www-form-urlencoded"
                            ]
                        ];
                        $postmanFolderItem['request']['body'] = [];
                        $postmanFolderItem['request']['body']['mode'] = 'urlencoded';
                        $postmanFolderItem['request']['body']['urlencoded'] = $queries;
                    }
                }

                if (isset($item['success'])) {
                    $postmanResponses = [];
                    foreach ($item['success']['examples'] as $responseExample) {
                        $postmanResponse = [
                            'name'   => $responseExample['title'],
                            'body'   => ltrim($responseExample['content'], "HTTP/1.1 200 OK \n"),
                            'status' => '200 OK',
                            'code'   => 200,
                        ];

                        if ($responseExample['title'] === '400') {
                            $postmanResponse['status'] = $responseExample['title'] . ' Bad Request';
                            $postmanResponse['code'] = (int)$responseExample['title'];
                        } else if ($responseExample['title'] === '403') {
                            $postmanResponse['status'] = $responseExample['title'] . ' Forbidden';
                            $postmanResponse['code'] = (int)$responseExample['title'];
                        } else if ($responseExample['title'] === '404') {
                            $postmanResponse['status'] = $responseExample['title'] . ' Not Found';
                            $postmanResponse['code'] = (int)$responseExample['title'];
                        } else if ($responseExample['title'] === '500') {
                            $postmanResponse['status'] = $responseExample['title'] . ' Internal Error';
                            $postmanResponse['code'] = (int)$responseExample['title'];
                        }

                        $postmanResponses[] = $postmanResponse;
                    }

                    $postmanFolderItem['response'] = $postmanResponses;
                    $postmanFolderItems[] = $postmanFolderItem;
                }
                $postmanFolder['item'] = $postmanFolderItems;
            }

            if ($postmanFolderItems) {
                $postmanItems[] = $postmanFolder;
            }
        }

        $postmanArray['collection']['item'] = $postmanItems;

        return json_encode($postmanArray, JSON_UNESCAPED_UNICODE);
    }
}