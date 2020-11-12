<?php

namespace Imanghafoori\Tags\Services;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Imanghafoori\Tags\Models\TempTag;

class TagService
{
    public static $registeredRelation = [];

    private static $maxLifeTime = '2038-01-01 00:00:00';

    private static $dateFormat = 'Y-m-d H:i:s';

    private $model;

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function cache()
    {
        return cache()->store('temp_tag');
    }

    public function getActiveTag(string $tag)
    {
        return $this->cache()->get($this->getCacheKey($tag));
//        return $this->getActiveTagFromDB($tag);
    }

    public function getTag(string $tag): ?TempTag
    {
        return $this->getTagQuery($tag)->first();
    }

    public function getAllExpiredTags()
    {
        return $this->query()->expired()->get();
    }

    public function getAllActiveTags()
    {
        return $this->query()->active()->get();
    }

    public function getAllTags()
    {
        return $this->query()->get();
    }

    public function getTagsExpireAfter(Carbon $date)
    {
        return $this->query()->where('expired_at', '>', $date->format(self::$dateFormat))->get();
    }

    public function getTagsExpireBefore(Carbon $date)
    {
        return $this->query()->where('expired_at', '<', $date->format(self::$dateFormat))->get();
    }

    public function getExpiredTag(string $tag)
    {
        return $this->getTagQuery($tag)->expired()->first();
    }

    public function tagIt($tagTitles, $expDate = null, $payload = null, $eventName = null)
    {
        $data = $this->getTaggableWhere();
        $exp = $this->expireDate($expDate);

        $newTags = [];
        foreach ((array) $tagTitles as $title) {
            $data['title'] = $title;
            $newTags[] = $tagObj = TempTag::query()->updateOrCreate($data, $data + $exp + ['payload' => $payload]);
            $this->fireEvent($eventName, $tagObj);
            $this->putInCache($expDate, $tagObj);
        }

        return $newTags;
    }

    public function unTag($titles = null)
    {
        $this->deleteAll($this->queryTitles($titles)->get());
    }

    public function getTagCount($titles = null)
    {
        return $this->queryTitles($titles)->count();
    }

    public function getActiveTagCount($titles = null)
    {
        return $this->queryTitles($titles)->active()->count();
    }

    public function getExpiredTagCount($titles = null)
    {
        return $this->queryTitles($titles)->expired()->count();
    }

    public function deleteExpiredTags()
    {
        $tags = TempTag::query()
            ->expired()
            ->get();

        $this->deleteAll($tags);
    }

    private function getTaggableWhere()
    {
        $taggable = $this->model;

        return [
            'taggable_id'   => $taggable->getKey(),
            'taggable_type' => $taggable->getTable(),
        ];
    }

    private function query()
    {
        return TempTag::query()->where($this->getTaggableWhere());
    }

    private function fireEvent($event, $tag)
    {
        ! $event && $event = 'tmp_tagged';

        $event .= ':'.$this->model->getTable().','.$tag->title;

        event($event, [$this->model, $tag]);
    }

    private function expireDate($carbon)
    {
        $carbon = $carbon ? $carbon->format(self::$dateFormat) : self::$maxLifeTime;

        return ['expired_at' => $carbon];
    }

    public function expireNow($tag)
    {
        $this->tagIt($tag, Carbon::now()->subSeconds(1), 'tmp_tag_expired');
    }

    private function deleteAll($tags)
    {
        $tags->each(function ($tag) {
            $tag->delete();
        });
    }

    private function getTagQuery(string $tagTitle)
    {
        return $this->query()->where('title', $tagTitle);
    }

    private function getCacheKey($title)
    {
        return 'temp_tag:'.$this->model->getTable().$this->model->getKey().','.$title;
    }

    private function putInCache($expDate, $tagObj)
    {
        $key = $tagObj->getCacheKey();
        if (is_null($expDate)) {
            return $this->cache()->forever($key, $tagObj);
        }

        if ($expDate->timestamp < now()->timestamp) {
            $this->cache()->delete($key);
        } else {
            $this->cache()->put($key, $tagObj, $expDate);
        }
    }

    private function getActiveTagFromDB($tagTitle)
    {
        return $this->getTagQuery($tagTitle)->active()->first() ?: null;
    }

    private function queryTitles($titles)
    {
        $forTaggable = $this->getTaggableWhere();

        $tagsQuery = TempTag::query()->where($forTaggable);

        $titles && TagService::getClosure($titles, [])($tagsQuery);

        return $tagsQuery;
    }

    public static function registerRelationship($q)
    {
        $table = $q->getModel()->getTable();
        if (! in_array($table, TagService::$registeredRelation)) {
            TagService::$registeredRelation[] = $table;
            Relation::morphMap([$table => get_class($q->getModel())]);
        }
    }

    public static function whereHasClosure($relation, $method)
    {
        return function ($title, $payload = []) use ($relation, $method) {
            TagService::registerRelationship($this);

            return $this->$method($relation, TagService::getClosure($title, $payload));
        };
    }

    public static function getClosure($title, $payload)
    {
        return function ($q) use ($title, $payload) {
            if (is_string($title) && Str::contains($title, ['*'])) {
                $title = str_replace('*', '%', $title);
                $q->where('title', 'like', $title);
            } else {
                $q->whereIn('title', (array) $title);
            }

            foreach ($payload as $key => $value) {
                $q->where('payload->'.$key, $value);
            }
        };
    }
}
