// exit: 15
// stdout: 5
// stdout: 10
// stdout: 15

struct Counter {
    value: i32,
}

impl Counter {
    fn new() -> Counter {
        Counter { value: 0 }
    }

    fn increment(&mut self, amount: i32) {
        self.value = self.value + amount;
    }

    fn get(&self) -> i32 {
        self.value
    }
}

fn main() {
    let mut c = Counter::new();
    c.increment(5);
    println!("{}", c.get());
    c.increment(5);
    println!("{}", c.get());
    c.increment(5);
    println!("{}", c.get());
    exit(c.get());
}
