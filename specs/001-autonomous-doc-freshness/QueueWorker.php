<?php
namespace DigipuntTheme;

class QueueWorker
{
    private $db;

    public function __construct()
    {
        $this->db = \DB::connection();
    }

    public function enqueue($entityType, $entityId)
    {
        $id = $this->db->table('page_update_queue')->insertGetId([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->process($id);

        return $id;
    }

    public function process($queueId)
    {
        $this->db->table('page_update_queue')
            ->where('id', $queueId)
            ->update(['status' => 'processing', 'updated_at' => now()]);

        try {
            $model = LLMUpdater::getBestModel();
            $result = LLMUpdater::updatePage($queueId, $model);

            $this->db->table('page_update_queue')
                ->where('id', $queueId)
                ->update([
                    'status' => 'done',
                    'model_used' => $model,
                    'draft_html' => $result['draft_html'],
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            $this->db->table('page_update_queue')
                ->where('id', $queueId)
                ->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function publish($queueId)
    {
        $entry = $this->db->table('page_update_queue')->where('id', $queueId)->first();
        if ($entry && $entry->draft_html) {
            $this->db->table('pages')
                ->where('id', $entry->entity_id)
                ->update(['html' => $entry->draft_html, 'updated_at' => now()]);

            $this->db->table('page_update_queue')
                ->where('id', $queueId)
                ->update(['status' => 'published', 'updated_at' => now()]);
        }
    }

    public function reject($queueId)
    {
        $this->db->table('page_update_queue')
            ->where('id', $queueId)
            ->update(['status' => 'rejected', 'updated_at' => now()]);
    }

    public function listPending()
    {
        return $this->db->table('page_update_queue')
            ->where('status', 'pending')
            ->get()
            ->toArray();
    }
}