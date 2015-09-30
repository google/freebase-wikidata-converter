#pragma once

#include "PropagatedValue.h"

#include <unordered_set>
#include <string>

class PngCreator {
public:
    void createPng(
            const std::string& fileNamePrefix,
            const std::unordered_set<size_t>& ids,
            const PropagatedValue<size_t, long long int, Min<long long int>>& time,
            const PropagatedValue<size_t, float, Average<float>>& longitudes);
};

