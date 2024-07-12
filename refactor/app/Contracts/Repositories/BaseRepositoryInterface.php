<?php

namespace DTApi\Contracts\Repositories;

interface BaseRepositoryInterface
{
    public function all();

    public function find($id);

    public function create(array $data);

    public function update($id, array $data);

    public function delete($id);

    public function with($array);
}
