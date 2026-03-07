// stdout: 0
// stdout: 10
// stdout: 25
// exit: 25

struct Acc {
    value: i32,
}

impl Acc {
    fn new() -> Acc {
        Acc { value: 0 }
    }
    fn add(&mut self, n: i32) {
        self.value = self.value + n;
    }
    fn get(&self) -> i32 {
        self.value
    }
}

fn main() {
    let mut a = Acc::new();
    println!("{}", a.get());
    a.add(10);
    println!("{}", a.get());
    a.add(15);
    println!("{}", a.get());
    exit(a.get());
}
