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


std::string extractMid(const std::string& str) {
    return str.substr(28, str.length() - 29);
}

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
    static boost::regex type_literal_regex("\"(.+)\"\\^\\^<([^<>]+)>");
    static boost::regex simple_literal_regex("\"(.*)\"");
    static boost::regex year_month_regex("([+-]?\\d+)-(\\d{2})");
    static boost::regex year_month_day_regex("([+-]?\\d+)-(\\d{2})-(\\d{2})");
    std::unordered_map<size_t, size_t> location_cvt_for_topic;
    std::unordered_map<size_t, float> longitude_for_location_cvt;

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
        size_t subject_hash = hash(extractMid(subject));
        all_ids.insert(subject_hash);

        std::string predicate = tokens[1];

        std::string object = tokens[2];
        //boost::algorithm::trim(object);

        boost::smatch sm;
        if(object.find("<http://rdf.freebase.com/ns") == 0) {
            size_t object_hash = hash(extractMid(object));
            all_ids.insert(object_hash);
            graph.addEdge(subject_hash, object_hash);
            if(predicate == "<http://rdf.freebase.com/ns/location.location.geolocation>") {
                location_cvt_for_topic[subject_hash] = object_hash;
            }
        } else if(boost::regex_match(object, sm, type_literal_regex)) {
            std::string value = sm.str(1);
            std::string type = sm.str(2);
            if(type == "http://www.w3.org/2001/XMLSchema#gYear") {
                try {
                    time.addValue(subject_hash, std::stoll(value)*31*12);
                } catch(std::out_of_range&) {
                }
            } else if(type == "http://www.w3.org/2001/XMLSchema#gYearMonth" && boost::regex_match(value, sm, year_month_regex)) {
                try {
                    time.addValue(subject_hash, std::stoll(sm.str(1))*31*12 + std::stoll(sm.str(2))*31);
                } catch(std::out_of_range&) {
                }
            } else if((type == "http://www.w3.org/2001/XMLSchema#date" || type == "http://www.w3.org/2001/XMLSchema#dateTime") && boost::regex_match(value, sm, year_month_day_regex)) {
                try {
                    time.addValue(subject_hash, std::stoll(sm.str(1))*31*12 + std::stoll(sm.str(2))*31 + std::stoll(sm.str(3)));
                } catch(std::out_of_range&) {
                }
            }
        } else if(predicate == "<http://rdf.freebase.com/ns/location.geocode.longitude>" && boost::regex_match(object, sm, simple_literal_regex)) {
            longitude_for_location_cvt[subject_hash] = std::stof(sm.str(1));
        }
    }
    for(const std::pair<size_t,size_t>& topic_cvt : location_cvt_for_topic) {
        try {
            longitudes.addValue(topic_cvt.first, longitude_for_location_cvt.at(topic_cvt.second));
        } catch(std::out_of_range&) {
        }
    }
    std::cout << std::endl << "propagate time" << std::endl;
    time.propagate(graph);

    std::cout << "propagate coordinates" << std::endl;
    longitudes.propagate(graph);

    std::cout << "output" << std::endl;
    PngCreator png_creator;
    png_creator.createPng(std::string("freebase"), all_ids, time, longitudes);

	return 0;
}
