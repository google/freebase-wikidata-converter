#include "Graph.h"
#include "PropagatedValue.h"
#include "PngCreator.h"

#include <boost/tokenizer.hpp>
#include <boost/regex.hpp>
#include <boost/algorithm/string.hpp>

#include <iostream>
#include <fstream>
#include <string>
#include <vector>
#include <unordered_set>
#include <unordered_map>

int main(int argc, char* argv[]) {
    if(argc < 2) {
        std::cout << "you should provide an input file" << std::endl;
    }

    //Structures
    Graph<size_t> graph;
    PropagatedValue<size_t, long long int, Min<long long int>> time;
    PropagatedValue<size_t, float, Average<float>> longitudes;
    std::unordered_set<size_t> all_ids;

    //Parsing
    std::cout << "parsing" << std::endl;
    typedef boost::tokenizer<boost::char_separator<char>> Tokenizer;
    static boost::char_separator<char> sep("\t");
    static boost::regex time_regex("([+-]\\d+)-(\\d{2})-(\\d{2})T\\d{2}:\\d{2}:\\d{2}Z/\\d{1,2}");
    static boost::regex coordinates_regex("@([+-]?\\d{1,2}(\\.\\d+)?)/([+-]?\\d{1,3}(\\.\\d+))?");

    std::string line;
    std::vector<std::string> tokens;
    std::hash<std::string> hash;
    std::ifstream input(argv[1]);
    int count = 0;
    while(std::getline(input, line)) {
        count++;
        if((count % 1000000) == 0) {
            std::cout << "." << std::flush;
        }

        Tokenizer tok(line, sep);
        tokens.assign(tok.begin(), tok.end());
        if(tokens.size() < 3) {
            continue;
        }
        std::string subject = tokens[0];
        boost::algorithm::trim(subject);
        size_t subject_hash = hash(subject);
        all_ids.insert(subject_hash);

        std::string object = tokens[2];
        boost::algorithm::trim(object);


        boost::smatch sm;
        switch(object[0]) {
            case 'Q': {
                size_t object_hash = hash(object);
                all_ids.insert(object_hash);
                graph.addEdge(subject_hash, object_hash);
                graph.addEdge(object_hash, subject_hash);
                break;
            }
            case '+':
            case '-':
                if(boost::regex_match(object, sm, time_regex)) {
                    try {
                        time.addValue(subject_hash, std::stoll(sm.str(1))*31*12 + std::stoll(sm.str(2))*31 + std::stoll(sm.str(3)));
                    } catch(std::out_of_range&) {}
                }
                break;
            case '@':
                if(boost::regex_match(object, sm, coordinates_regex)) {
                    try {
                        longitudes.addValue(subject_hash, std::stof(sm.str(3)));
                    } catch(std::out_of_range&) {}
                    }
                break;
        }
    }

    std::cout << std::endl << "propagate time" << std::endl;
    time.propagate(graph);

    std::cout << "propagate coordinates" << std::endl;
    longitudes.propagate(graph);

    std::cout << "output" << std::endl;
    PngCreator png_creator;
    png_creator.createPng(std::string("wikidata"), all_ids, time, longitudes);

	return 0;
}
