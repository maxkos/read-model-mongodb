<?php

namespace Broadway\ReadModel\MongoDB;

use Assert\Assertion as Assert;
use Broadway\ReadModel\Identifiable;
use Broadway\ReadModel\Repository;
use Broadway\Serializer\Serializer;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;

/**
 * @author Robin van der Vleuten <robin@webstronauts.co>
 */
class MongoDBRepository implements Repository
{
    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var string
     */
    private $class;

    /**
     * @param Collection $collection
     * @param Serializer $serializer
     * @param string     $class
     */
    public function __construct(Collection $collection, Serializer $serializer, string $class)
    {
        $this->collection = $collection;
        $this->serializer = $serializer;
        $this->class = $class;
    }

    /**
     * {@inheritdoc}
     */
    public function save(Identifiable $model)
    {
        Assert::isInstanceOf($model, $this->class);

        $normalized = $this->normalizeIdentifiable($model);

        $this->collection->insertOne($normalized);
    }

    /**
     * {@inheritdoc}
     */
    public function find($id)
    {
        $document = $this->collection->findOne(['_id' => (string) $id]);

        return $document ? $this->denormalizeIdentifiable($document) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $fields)
    {
        return $this->findModelsByQuery($fields);
    }

    /**
     * {@inheritdoc}
     */
    public function findAll()
    {
        return $this->findModelsByQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function remove($id)
    {
        $this->collection->deleteOne(['_id' => (string) $id]);
    }

    /**
     * @param array $query
     *
     * @return Identifiable[]
     */
    private function findModelsByQuery(array $query = [])
    {
        if (empty($query)) {
            return [];
        }

        return array_map(function ($document) {
            return $this->denormalizeIdentifiable($document);
        }, $this->collection->find($query)->toArray());
    }

    /**
     * @param Identifiable $model
     *
     * @return array
     */
    private function normalizeIdentifiable(Identifiable $model)
    {
        $serialized = $this->serializer->serialize($model);

        return array_reduce(array_keys($serialized['payload']), function ($normalized, $key) use ($serialized) {
            return array_merge($normalized, [ $key === 'id' ? '_id' : $key => $serialized['payload'][$key] ]);
        }, ['class' => $serialized['class']]);
    }

    /**
     * @param BSONDocument $document
     *
     * @return Identifiable
     */
    private function denormalizeIdentifiable(BSONDocument $document)
    {
        // Tiny hack to convert BSON types to PHP types.
        // I thought that I can use https://secure.php.net/manual/en/function.mongodb.bson-tophp.php here,
        // but apparently this method does not handle nested BSON types very well.
        $data = json_decode(json_encode($document), true);

        $payload = array_reduce(array_diff(array_keys($data), ['_id', 'class']), function ($payload, $key) use ($data) {
            return array_merge($payload, [ $key => $data[$key] ]);
        }, ['id' => $data['_id']]);

        return $this->serializer->deserialize([
            'id' => $data['_id'],
            'class' => $data['class'],
            'payload' => $payload,
        ]);
    }
}