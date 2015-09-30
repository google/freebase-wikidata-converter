#pragma once

#include "Graph.h"

#include <string>
#include <unordered_map>


/**
 * Does all what is related to a propagated value
 * Value: type of the value
 * Merge: struct whose function call operator is used to merge a new added value with the existing one
 */
template<typename Id, typename Value, typename Merge> class PropagatedValue {
public:
    void addValue(const Id& id, const Value& value);
    Value getValue(const Id& id) const;
    void propagate(const Graph<Id>& graph);

private:
    const Value MAX_PONDERATION = 1024;

    std::unordered_map<Id, Value> fixed_values;
    std::unordered_map<Id, Value> all_values;
    std::unordered_map<Id, Value> ponderations;
    Merge merge;

    void propagateFrom(const Id& id, const Value& value, Value ponderation, const Graph<Id>& graph);
};

#include "PropagatedValue.cpp"


template<typename Value> struct Min {
    Value operator() (const Value& a, const Value& b) const {
        return std::min<Value>(a, b);
    }
};

template<typename Value> struct Average {
    Value operator() (const Value& a, const Value& b) const {
        return (a + b) / 2;
    }
};
