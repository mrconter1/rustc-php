// exit: 30

struct Pair<T> {
    first: T,
    second: T,
}

fn sum_pair(p: Pair<i32>) -> i32 {
    p.first + p.second
}

fn main() {
    let p = Pair { first: 10, second: 20 };
    exit(sum_pair(p));
}
