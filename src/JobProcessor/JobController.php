<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Job;
use Zan\Framework\Components\JobServer\MqJobManager;
use Zan\Framework\Foundation\Container\Di;
use Zan\Framework\Foundation\Domain\HttpController;
use Zan\Framework\Network\Http\Response\Response;
use Zan\Framework\Contract\Network\Request;
use Zan\Framework\Utilities\DesignPattern\Context;


class JobController extends HttpController
{
    /**
     * @var \Zan\Framework\Network\Http\Request\Request
     */
    protected $request;

    public function __construct(Request $request, Context $context)
    {
        parent::__construct($request, $context);
        $context->set("__job_mgr", $this->getJobManager());
        $context->set("__job", $this->getJob());
    }

    /**
     * @return Job|null
     */
    public function getJob()
    {
        return $this->request->server(JobRequest::JOB_PARA_KEY);
    }

    /**
     * @return JobManager|null
     */
    public function getJobManager()
    {
        return $this->request->server(JobRequest::JOB_MGR_PARA_KEY);
    }

    public function jobDone()
    {
        $job = $this->getJob();
        if ($job) {
            $this->getJobManager()->done($job);
            yield new Response("", 200);
        } else {
            yield new Response("", 404);
        }
    }

    /**
     * @param \Exception|string $reason
     * @return \Generator
     */
    public function jobError($reason)
    {
        if ($job = $this->getJob()) {
            $this->getJobManager()->error($job, $reason);
            yield new Response("", 500);
        } else {
            yield new Response("", 404);
        }
    }

    /**
     * MQ 提交任务
     * @param string $topic mq topic
     * @param $task
     * @return \Generator
     */
    public function submit($topic, $task)
    {
        $mqJobMrg = Di::make(MqJobManager::class, [], true);
        yield $mqJobMrg->submit($topic, $task);
    }
}