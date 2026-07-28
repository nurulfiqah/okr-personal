-- Adds a manual drag-to-reorder position for Subtasks (Key Result Progress),
-- modeled after iidas's project_detail.php Task/Subtask drag-and-drop
-- (iidas_task.order column). Defaults to 0 for all existing rows, which is
-- fine - okrFetchKeyResults() orders by (sort_order, id), so untouched rows
-- just fall back to creation order until someone actually drags one.

ALTER TABLE `okr_key_results`
  ADD COLUMN `sort_order` int NOT NULL DEFAULT '0' AFTER `end_date`;
