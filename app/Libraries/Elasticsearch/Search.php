<?php

/**
 *    Copyright 2015-2017 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Libraries\Elasticsearch;

use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Search implements Queryable
{
    use HasSearch;

    const HIGHLIGHT_FRAGMENT_SIZE = 50;
    // maximum number of total results allowed when not using the scroll API.
    const MAX_RESULTS = 10000;

    protected $index;
    protected $options;

    private $count;
    private $error;
    private $response;

    public function __construct(string $index, array $options = [])
    {
        $this->index = $index;
        $this->options = $options;
    }

    // for paginator
    abstract public function data();

    /**
     * Gets the numner of matches for the query.
     *
     * @return int the number of matches.
     */
    public function count() : int
    {
        // use total from response if response was already fetched.
        if (isset($this->response)) {
            return $this->response->total();
        }

        if (!isset($this->count)) {
            $query = $this->toArray();
            // some arguments need to be stripped from the body as they're not supported by count.
            $body = $query['body'];
            foreach (['from', 'size', 'sort'] as $key) {
                unset($body[$key]);
            }

            $query['body'] = $body;

            $this->count = Es::count($query)['count'];
        }

        return $this->count;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getPaginator(array $options = [])
    {
        $page = $this->getPaginationParams();
        if (!isset($page['page'])) {
            // no laravel paginator if offset-only paging is used
            return;
        }

        return new SearchPaginator(
            $this,
            $page['size'],
            $page['page'],
            $options
        );
    }

    /**
     * Returns if the query string is considered valid. Does not account for query errors.
     *
     * @return bool true if the query string is valid; false, otherwise.
     */
    public function isValidQueryString() : bool
    {
        return true;
    }

    /**
     * @return SearchResponse
     */
    public function response() : SearchResponse
    {
        if (!isset($this->response)) {
            $this->response = $this->fetch();
        }

        return $this->response;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray() : array
    {
        $pageParams = $this->getPaginationParams();

        $body = [
            'from' => $pageParams['from'],
            'size' => $pageParams['size'],
            'sort' => array_map(function ($sort) {
                return $sort->toArray();
            }, $this->sorts),
        ];

        if (isset($this->highlight)) {
            $body['highlight'] = $this->highlight->toArray();
        }

        if (isset($this->source)) {
            $body['_source'] = $this->source;
        }

        $body['query'] = QueryHelper::clauseToArray($this->query);

        $json = ['body' => $body, 'index' => $this->index];

        if (isset($this->type)) {
            $json['type'] = $this->type;
        }

        return $json;
    }

    public function total()
    {
        return min($this->response()->total(), static::MAX_RESULTS);
    }

    private function fetch()
    {
        try {
            return new SearchResponse(Es::search($this->toArray()));
        } catch (NoNodesAvailableException $e) {
            // all servers down
            $this->error = $e;
        } catch (BadRequest400Exception $e) {
            // invalid query
            $this->error = $e;
        } catch (Missing404Exception $e) {
            // index is missing ?_?
            $this->error = $e;
        }

        return SearchResponse::failed();
    }

    protected function getDefaultSize() : int
    {
        return 50;
    }
}
