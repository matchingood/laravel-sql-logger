<?php

namespace Mnabialek\LaravelSqlLogger;

use Mnabialek\LaravelSqlLogger\Objects\SqlQuery;
use App\Libs\SlackNotification;
use App\Libs\Util;

use App\Notifications\SlackPosted;

class Writer
{
    /**
     * @var Formatter
     */
    private $formatter;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var FileName
     */
    private $fileName;

    const SLOW_QUERY_SLACK_TITLE = 'スロークエリー';

    /**
     * Writer constructor.
     *
     * @param Formatter $formatter
     * @param Config $config
     * @param FileName $fileName
     */
    public function __construct(Formatter $formatter, Config $config, FileName $fileName)
    {
        $this->formatter = $formatter;
        $this->config = $config;
        $this->fileName = $fileName;
    }

    /**
     * Save queries to log.
     *
     * @param SqlQuery $query
     */
    public function save(SqlQuery $query)
    {
        $this->createDirectoryIfNotExists($query->number());

        $line = $this->formatter->getLine($query);
        $context = $this->formatter->getSlackRecord($query);
        if ($this->shouldLogQuery($query)) {
            $this->saveLine($line, $this->fileName->getForAllQueries(), $this->shouldOverrideFile($query));
        }

        if ($this->shouldLogSlowQuery($query)) {
            $this->toSlack($context);
            $this->saveLine($line, $this->fileName->getForSlowQueries());
        }
    }

    /**
     * Create directory if it does not exist yet.
     *
     * @param int $queryNumber
     */
    protected function createDirectoryIfNotExists($queryNumber)
    {
        if ($queryNumber == 1 && ! file_exists($directory = $this->directory())) {
            mkdir($directory, 0777, true);
        }
    }

    /**
     * Get directory where file should be located.
     *
     * @return string
     */
    protected function directory()
    {
        return rtrim($this->config->logDirectory(), '\\/');
    }

    /**
     * Verify whether query should be logged.
     *
     * @param SqlQuery $query
     *
     * @return bool
     */
    protected function shouldLogQuery(SqlQuery $query)
    {
        return $this->config->logAllQueries() &&
            preg_match($this->config->allQueriesPattern(), $query->raw());
    }

    /**
     * Verify whether slow query should be logged.
     *
     * @param SqlQuery $query
     *
     * @return bool
     */
    protected function shouldLogSlowQuery(SqlQuery $query)
    {
        return $this->config->logSlowQueries() && $query->time() >= $this->config->slowLogTime() &&
            preg_match($this->config->slowQueriesPattern(), $query->raw());
    }

    /**
     * Save data to log file.
     *
     * @param string $line
     * @param string $fileName
     * @param bool $override
     */
    protected function saveLine($line, $fileName, $override = false)
    {
        file_put_contents($this->directory() . DIRECTORY_SEPARATOR . $fileName,
            $line, $override ? 0 : FILE_APPEND);
    }

    /**
     * Verify whether file should be overridden.
     *
     * @param SqlQuery $query
     *
     * @return bool
     */
    private function shouldOverrideFile(SqlQuery $query)
    {
        return ($query->number() == 1 && $this->config->overrideFile());
    }

    private function toSlack($context)
    {
        $record['context'] = $context;
        $record['extra'] = [];

        if (isset($record['context']['gitHash'])) {
            $record['context']['gitHash']
                = substr($record['context']['gitHash'], 0, 30);
        }

        $notification = (new SlackNotification())
            ->setLevel(SlackNotification::ERROR)
            ->setIsAnnounced(false)
            ->setAttachmentTitle(self::SLOW_QUERY_SLACK_TITLE)
            ->setFields(array_merge($record['context'], $record['extra']));
        if (!empty($notification->routeNotificationForSlack())) {
            $notification->notify(new SlackPosted);
            return;
        }
    }
}
