<?php

namespace Biz\Task\Dao;

use Codeages\Biz\Framework\Dao\GeneralDaoInterface;

interface TaskDao extends GeneralDaoInterface
{
    public function deleteByCategoryId($categoryId);

    public function findByCourseId($courseId);

    public function getByCourseIdAndNumber($courseId, $number);

    public function getMaxSeqByCourseId($courseId);

    public function getMaxNumberByCourseId($courseId);

    public function findTasksByChapterId($chapterId);

    public function waveSeqBiggerThanSeq($courseId, $seq, $diff);
}