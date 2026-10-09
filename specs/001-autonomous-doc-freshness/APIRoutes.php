<?php
namespace DigipuntTheme;

class APIRoutes
{
    public static function getRoutes()
    {
        return [
            ['method'=>'POST', 'uri'=>'/api/digipunt/update', 'action'=>'updatePage'],
            ['method'=>'POST', 'uri'=>'/api/digipunt/sources/check', 'action'=>'checkSources'],
            ['method'=>'GET',  'uri'=>'/api/digipunt/pending', 'action'=>'listPending'],
            ['method'=>'POST', 'uri'=>'/api/digipunt/publish', 'action'=>'publishDraft'],
            ['method'=>'POST', 'uri'=>'/api/digipunt/reject', 'action'=>'rejectDraft'],
            ['method'=>'POST', 'uri'=>'/api/digipunt/pages/{id}/update', 'action'=>'updatePageById'],
        ];
    }

    public static function updatePage()
    {
        $page = json_decode(file_get_contents('php://input'), true);
        $queue = new QueueWorker();
        $id = $queue->enqueue($page['entity_type'], $page['entity_id']);
        echo json_encode(['status'=>'queued','id'=>$id]);
    }

    public static function listPending()
    {
        $queue = new QueueWorker();
        echo json_encode($queue->listPending());
    }

    public static function publishDraft()
    {
        $queue = new QueueWorker();
        $queue->publish(json_decode(file_get_contents('php://input'), true)['id']);
        echo json_encode(['status'=>'published']);
    }

    public static function rejectDraft()
    {
        $queue = new QueueWorker();
        $queue->reject(json_decode(file_get_contents('php://input'), true)['id']);
        echo json_encode(['status'=>'rejected']);
    }

    public static function updatePageById($id)
    {
        $queue = new QueueWorker();
        $queueId = $queue->enqueue('page', $id);
        echo json_encode(['status'=>'queued','id'=>$queueId,'page_id'=>$id]);
    }
}