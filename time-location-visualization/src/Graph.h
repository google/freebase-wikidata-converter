#pragma once

#include <string>
#include <vector>
#include <unordered_map>


//A directed multigraph
template<typename T> class Graph {
public:
    void addEdge(const T& start, const T& end) {
        graph[start].push_back(end);
    }

    const std::vector<T> getAdjacents(const T& vertex) const {
        try {
            return graph.at(vertex);
        } catch(std::out_of_range&) {
            return std::vector<T>();
        }
    }

private:
    std::unordered_map<T, std::vector<T>> graph;
};
