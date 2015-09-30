#include <iostream>
#include "PropagatedValue.h"

template<typename Id, typename Value, typename Merge>
void PropagatedValue<Id, Value, Merge>::addValue(const Id& id, const Value& value) {
    if(fixed_values.find(id) == fixed_values.end()) {
        fixed_values[id] = value;
    } else {
        fixed_values[id] = merge(fixed_values[id], value);
    }
}


template<typename Id, typename Value, typename Merge>
Value PropagatedValue<Id, Value, Merge>::getValue(const Id& id) const {
    return all_values.at(id);
}


template<typename Id, typename Value, typename Merge>
void PropagatedValue<Id, Value, Merge>::propagate(const Graph<Id>& graph) {
    all_values = fixed_values;

    //Propagate the values
    for(const std::pair<Id,Value>& v : fixed_values) {
        propagateFrom(v.first, v.second, PropagatedValue::MAX_PONDERATION, graph);
    }

    //Normalize
    for(const std::pair<Id,Value>& v : ponderations) {
        all_values[v.first] /= v.second;
    }
}


template<typename Id, typename Value, typename Merge>
void PropagatedValue<Id, Value, Merge>::propagateFrom(const Id& id, const Value& value, Value ponderation, const Graph<Id>& graph) {
    ponderation /= 2;
    if(ponderation <= 1) {
        return;
    }

    for(const Id& adjacent : graph.getAdjacents(id)) {
        if(fixed_values.count(adjacent) > 0) {
            continue;
        }

        all_values[adjacent] += value * ponderation;
        ponderations[adjacent] += ponderation;
    }
}
